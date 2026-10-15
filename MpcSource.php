<?php

namespace Omnilab\Mpc;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The IAU's Minor Planet Center (data.minorplanetcenter.net): a minor
 * planet, a comet or a natural satellite by its number, its designation
 * (provisional, packed or not, primary or secondary) or its name - its
 * designations, name and naming citation, its orbit as the MPC computes it
 * (the osculating elements with their uncertainties at their epoch, the
 * perihelion, the period, the MOIDs), its absolute magnitude, how it was
 * observed (the observations, oppositions and arc, the uncertainty
 * parameter U); a search of the names; and, outside the contract, its
 * latest observations (observations()).
 *
 * No key. The MPC's APIs take their query as a JSON body on a GET (their
 * POST answers 405). Two calls an entity: the Designation Identifier, then
 * the orbit.
 */
final class MpcSource extends HttpSource
{
    public const BASE_URI = 'https://data.minorplanetcenter.net/api/';

    /** The most observations observations() gives at once. */
    public const MAX_OBSERVATIONS = 1000;

    /** The most names a search page holds. */
    public const MAX_PER_PAGE = 100;

    /** The Keplerian and cometary elements read: name => [key, label, unit]. */
    private const ELEMENTS = [
        'a' => ['semi_major_axis', 'Semi-major axis', 'au'],
        'q' => ['perihelion_distance', 'Perihelion distance', 'au'],
        'e' => ['eccentricity', 'Eccentricity', null],
        'i' => ['inclination', 'Inclination (to the ecliptic)', 'deg'],
        'node' => ['ascending_node', 'Longitude of the ascending node', 'deg'],
        'argperi' => ['argument_of_perihelion', 'Argument of perihelion', 'deg'],
        'mean_anomaly' => ['mean_anomaly', 'Mean anomaly', 'deg'],
    ];

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'mpc';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A body by what the Designation Identifier reads: a number (433, its
     * IAU form (433) too), a designation (2004 MN4, K04M04N packed, a
     * comet's 1P or C/2020 F3), a name (Eros); an Identifier of the mpc or
     * the jpl.sbdb scheme (the same designations). A name several bodies
     * share ("Halley": 1P/Halley and (2688) Halley) is null rather than a
     * guess; another base's identifier is refused before any call.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $text = self::designation($id) ?? throw NotSupportedException::identifier('mpc', (string) $id);
        $record = $this->lookup($text);
        if (null === $record) {
            return null;
        }
        $desig = self::text($record['permid'] ?? null) ?? self::text($record['unpacked_primary_provisional_designation'] ?? null);
        if (null === $desig) {
            return null;
        }
        $data = $this->getForJson('get-orb', ['desig' => $desig]);
        $orbit = $data[0]['mpc_orb'][0] ?? null;

        return $this->toEntity($record, \is_array($orbit) ? $orbit : null);
    }

    /**
     * The bodies whose name holds the text, whatever its case, the closest
     * first (the MPC's similarity), one page at a time: each with its
     * designations, name and citation (entity() reads its orbit).
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::ASTRONOMY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~[%_\s]+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $key = '%'.$text.'%';
        $data = $this->getForJson('query-identifier', ['ids' => [$key], 'comparison' => 'ILIKE']) ?? [];
        $answer = $data[$key] ?? [];
        // One body found is answered as itself, several as a list.
        $list = (int) ($answer['found'] ?? 0) > 1 ? ($answer['disambiguation_list'] ?? []) : ((int) ($answer['found'] ?? 0) === 1 ? [$answer] : []);
        $offset = max(0, (int) $query->cursor);
        $limit = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $items = [];
        foreach (\array_slice($list, $offset, $limit) as $row) {
            if (\is_array($row) && null !== ($entity = $this->toEntity($row, null))) {
                $items[] = $entity;
            }
        }
        $total = \count($list);

        return new Page($items, $offset + $limit < $total ? (string) ($offset + $limit) : null, $total);
    }

    /**
     * The body's latest observations, the latest first (outside the
     * contract: an observation has a time and an observatory, not a place
     * the core reads, and the MPC answers every observation of a body at
     * once - Eros's 18,679 in 1.7 MB). Optical observations only, from the
     * 80-column format: a radar's and a satellite's second lines are not
     * read. Empty when the body is unknown.
     *
     * @return list<Observation>
     */
    public function observations(Identifier|string $id, int $limit = 20): array
    {
        $text = self::designation($id) ?? throw NotSupportedException::identifier('mpc', (string) $id);
        $data = $this->getForJson('get-obs', ['desigs' => [$text], 'output_format' => ['OBS80']]);
        $lines = \is_string($data[0]['OBS80'] ?? null) ? explode("\n", $data[0]['OBS80']) : [];
        $observations = [];
        foreach ($lines as $line) {
            if (null !== ($observation = Observation::fromObs80($line))) {
                $observations[] = $observation;
            }
        }
        usort($observations, static fn (Observation $a, Observation $b) => $b->time <=> $a->time);

        return \array_slice($observations, 0, max(1, min(self::MAX_OBSERVATIONS, $limit)));
    }

    /** What the Designation Identifier is asked for an identifier; null for another base's. */
    private static function designation(Identifier|string $id): ?string
    {
        if (\is_string($id)) {
            $parsed = Identifier::parse($id);
            if (null === $parsed) {
                return Identifier::tryOf(Scheme::MPC, $id)?->value;
            }
            $id = $parsed;
        }

        return \in_array($id->scheme, [Scheme::MPC, Scheme::JPL_SBDB], true) ? $id->value : null;
    }

    /**
     * The Designation Identifier's record of one body; null when it knows
     * none, or several.
     *
     * @return array<string, mixed>|null
     */
    private function lookup(string $text): ?array
    {
        $data = $this->getForJson('query-identifier', ['ids' => [$text]]) ?? [];
        $record = $data[$text] ?? (1 === \count($data) ? reset($data) : null);

        return \is_array($record) && 1 === (int) ($record['found'] ?? 0) ? $record : null;
    }

    /**
     * @param array<string, mixed>      $record the Designation Identifier's (or a search's row)
     * @param array<string, mixed>|null $orbit  the mpc_orb object
     */
    private function toEntity(array $record, ?array $orbit): ?Entity
    {
        $designations = $orbit['designation_data'] ?? $record;
        $permid = self::text($designations['permid'] ?? null);
        $primary = self::text($designations['unpacked_primary_provisional_designation'] ?? null);
        $name = self::text($designations['name'] ?? $record['name'] ?? null);
        $desig = $permid ?? $primary;
        if (null === $desig) {
            return null;
        }
        $type = self::text($orbit['categorization']['object_type_str'] ?? (\is_array($record['object_type'] ?? null) ? $record['object_type'][0] : null) ?? self::group($record['group'] ?? null));
        $comet = 'Comet' === $type || (bool) preg_match('~^\d+[PDI]$|^[PCDXI]/~', $desig);
        $label = match (true) {
            null === $name => $comet || null === $permid ? $desig : '('.$permid.')',
            $comet && null !== $permid => $permid.'/'.$name,
            $comet => $desig.' ('.$name.')',
            null !== $permid => '('.$permid.') '.$name,
            default => $desig.' '.$name,
        };
        $synonyms = array_values(array_unique(array_filter([$name, null !== $permid ? $primary : null, ...array_map(static fn ($d) => self::text($d), (array) ($designations['unpacked_secondary_provisional_designations'] ?? []))])));
        $citation = self::text(preg_replace('~<br\s*/?>~i', ' ', (string) ($designations['citation'] ?? $record['citation'] ?? '')));
        if ('\N' === $citation) {
            $citation = null;
        }

        $id = Identifier::of(Scheme::MPC, $desig);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::ASTRONOMY,
            kind: null === $type ? null : strtolower($type),
            synonyms: $synonyms,
            description: $citation,
            properties: null === $orbit ? [] : self::orbit($orbit, !$comet),
            // The same designations name it at JPL; its primary provisional designation is an MPC designation too.
            identifiers: new Identifiers([Identifier::tryOf(Scheme::JPL_SBDB, $desig), null !== $permid && null !== $primary ? Identifier::tryOf(Scheme::MPC, $primary) : null]),
            url: $id->url(),
            source: 'mpc',
        );
    }

    /**
     * The orbit's properties.
     *
     * @param array<string, mixed> $orbit
     *
     * @return list<Property>
     */
    private static function orbit(array $orbit, bool $minorPlanet): array
    {
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'mpc');
            }
        };
        // The MPC's orbit type (Amor, Apollo, Aten, main belt...) is a minor planet's; it gives one for comets too, which says nothing of them.
        if ($minorPlanet) {
            $add('orbit_type', 'Orbit type (MPC)', self::text($orbit['categorization']['orbit_type_str'] ?? null));
        }
        $epoch = $orbit['epoch_data'] ?? [];
        if (is_numeric($epoch['epoch'] ?? null) && 'MJD' === ($epoch['timeform'] ?? null)) {
            $add('epoch', 'Epoch of the osculating elements (TT)', self::mjd((float) $epoch['epoch'])?->format('Y-m-d H:i'));
            $add('epoch_mjd', 'Epoch of the osculating elements (MJD, TT)', 0 + $epoch['epoch']);
        }
        $seen = [];
        foreach (['KEP', 'COM'] as $set) {
            $names = $orbit[$set]['coefficient_names'] ?? [];
            $values = $orbit[$set]['coefficient_values'] ?? [];
            $sigmas = $orbit[$set]['coefficient_uncertainties'] ?? [];
            foreach ($names as $i => $name) {
                if (!is_numeric($values[$i] ?? null)) {
                    continue;
                }
                $sigma = is_numeric($sigmas[$i] ?? null) ? (float) $sigmas[$i] : null;
                if ('peri_time' === $name) {
                    $add('perihelion_time', 'Time of perihelion passage (TT)', self::mjd((float) $values[$i])?->format('Y-m-d H:i'));
                    $add('perihelion_time_mjd', 'Time of perihelion passage (MJD, TT)', new Quantity((float) $values[$i], 'd', $sigma));
                } elseif (isset(self::ELEMENTS[$name]) && !isset($seen[$name])) {
                    [$key, $label, $unit] = self::ELEMENTS[$name];
                    $seen[$name] = true;
                    $add($key, $label, null === $unit && null === $sigma ? (float) $values[$i] : new Quantity((float) $values[$i], $unit, $sigma));
                }
            }
        }
        $fit = $orbit['orbit_fit_statistics'] ?? [];
        $add('orbital_period', 'Sidereal orbital period', is_numeric($fit['period'] ?? null) ? new Quantity((float) $fit['period'], 'd') : null);
        $magnitude = $orbit['magnitude_data'] ?? [];
        $add('absolute_magnitude', 'Absolute magnitude (H)', is_numeric($magnitude['H'] ?? null) ? new Quantity((float) $magnitude['H'], 'mag') : null);
        $add('slope_parameter', 'Magnitude slope parameter (G)', is_numeric($magnitude['G'] ?? null) ? (float) $magnitude['G'] : null);
        foreach (['Earth' => 'moid', 'Venus' => 'moid_venus', 'Mars' => 'moid_mars', 'Jupiter' => 'moid_jupiter'] as $planet => $key) {
            $value = $orbit['moid_data'][$planet] ?? null;
            $add($key, 'Earth' === $planet ? 'Earth minimum orbit intersection distance (MOID)' : $planet.' MOID', is_numeric($value) ? new Quantity((float) $value, 'au') : null);
        }
        $add('uncertainty_parameter', 'Orbit uncertainty parameter (U: 0 best, 9 worst)', is_numeric($fit['U_param'] ?? null) ? (int) $fit['U_param'] : null);
        $add('orbit_quality', 'Orbit quality (MPC)', self::text($fit['orbit_quality'] ?? null));
        $add('observations', 'Observations', is_numeric($fit['nobs_total'] ?? null) ? (int) $fit['nobs_total'] : null);
        $add('observations_used', 'Observations used', is_numeric($fit['nobs_total_sel'] ?? null) ? (int) $fit['nobs_total_sel'] : null);
        $add('radar_observations', 'Radar observations', is_numeric($fit['nobs_radar'] ?? null) && $fit['nobs_radar'] > 0 ? (int) $fit['nobs_radar'] : null);
        $add('oppositions', 'Oppositions', is_numeric($fit['nopp'] ?? null) && $fit['nopp'] > 0 ? (int) $fit['nopp'] : null);
        $add('arc', 'Observed arc (years)', self::text($fit['arc_length_total'] ?? null));
        $add('rms', 'Normalised RMS of the fit', is_numeric($fit['normalized_RMS'] ?? null) ? (float) $fit['normalized_RMS'] : null);
        $add('non_gravitational', 'Non-gravitational forces in the fit', \is_bool($orbit['non_grav_booleans']['non_gravs'] ?? null) ? $orbit['non_grav_booleans']['non_gravs'] : null);
        $add('orbit_computed', 'Orbit computed (UTC)', self::time($orbit['software_data']['fitting_datetime'] ?? null)?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));

        return $properties;
    }

    /** A search row's group as the identifier's object type. */
    private static function group(mixed $group): ?string
    {
        return match ($group) {
            'Minor Planets' => 'Minor Planet',
            'Comets' => 'Comet',
            'Natural Satellites' => 'Natural Satellite',
            'Interstellar' => 'Interstellar Object',
            default => null,
        };
    }

    /** A modified Julian date (TT) as a time. */
    private static function mjd(float $mjd): ?\DateTimeImmutable
    {
        $seconds = (int) round(($mjd - 40587) * 86400);

        return (new \DateTimeImmutable('@'.$seconds))->setTimezone(new \DateTimeZone('UTC'));
    }
}
