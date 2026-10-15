<?php

namespace Omnilab\Mpc\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Mpc\MpcSource;
use Omnilab\Mpc\MpcSourceFactory;
use Omnilab\Mpc\Observation;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from data.minorplanetcenter.net/api on 2026-10-15:
 * the Designation Identifier's and the orbit's answers for 433 Eros, 1P
 * (Halley) and 2024 YR4, the observations of 2024 YR4 (533 lines in the
 * 80-column format), "Halley" (two bodies), a name no body has, and the
 * search for "eros" (thirteen names).
 */
final class MpcSourceTest extends TestCase
{
    /** @var list<array{string, string, array<mixed>}> the calls: method, path, body */
    private array $calls = [];

    private function source(?MockResponse $response = null): MpcSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $path = basename((string) parse_url($url, \PHP_URL_PATH));
            $body = json_decode($options['body'] ?? 'null', true);
            $this->calls[] = [$method, $path, $body];
            if ($response) {
                return $response;
            }
            $fixture = match ($path) {
                'query-identifier' => match ($body['ids'][0]) {
                    '433', 'Eros' => 'id-433.json',
                    '1P' => 'id-1P.json',
                    '2024 YR4' => 'id-2024-YR4.json',
                    'Halley' => 'id-halley.json',
                    '%eros%' => 'search-eros.json',
                    default => 'id-unknown.json',
                },
                'get-orb' => 'orb-'.str_replace(' ', '-', $body['desig']).'.json',
                'get-obs' => 'obs-'.str_replace(' ', '-', $body['desigs'][0]).'.json',
            };
            $text = (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture);
            // The Designation Identifier keys its answer by what it was asked.
            if ('Eros' === ($body['ids'][0] ?? null)) {
                $text = str_replace('"433":', '"Eros":', $text);
            }

            return new MockResponse($text);
        });
        $source = (new MpcSourceFactory($http))->create();
        self::assertInstanceOf(MpcSource::class, $source);

        return $source;
    }

    public function testErosByItsNumberInTwoCalls(): void
    {
        $eros = $this->source()->entity('433');

        self::assertSame([['GET', 'query-identifier', ['ids' => ['433']]], ['GET', 'get-orb', ['desig' => '433']]], $this->calls, 'a GET with a JSON body: the MPC\'s POST answers 405');
        self::assertSame('mpc:433', $eros->id->key());
        self::assertSame('(433) Eros', $eros->label);
        self::assertSame(Domain::ASTRONOMY, $eros->domain);
        self::assertSame('minor planet', $eros->kind);
        self::assertSame(['Eros', 'A898 PA', '1956 PC'], $eros->synonyms);
        self::assertNull($eros->description, 'the MPC\'s "\N": no citation');
        self::assertSame('https://minorplanetcenter.net/db_search/show_object?object_id=433', $eros->url);
        self::assertSame(['mpc:433', 'jpl.sbdb:433', 'mpc:A898 PA'], $eros->identifiers()->keys(), 'JPL names it the same');
        self::assertSame('Amor', $eros->value('orbit_type'));
        self::assertEquals(new Quantity(1.4582437174251626, 'au', 4.34582e-11), $eros->value('semi_major_axis'));
        self::assertEquals(new Quantity(0.222877980563273, null, 4.98248e-09), $eros->value('eccentricity'));
        self::assertSame('deg', $eros->property('inclination')->unit());
        self::assertEqualsWithDelta(10.828546685126, $eros->property('inclination')->number(), 1e-12);
        self::assertSame('au', $eros->property('perihelion_distance')->unit(), 'from the cometary elements');
        self::assertSame('2026-06-09 00:00', $eros->value('epoch'), 'MJD 61200');
        self::assertSame('10.395 mag', (string) $eros->property('absolute_magnitude'));
        self::assertSame('0.14759 au', (string) $eros->property('moid'));
        self::assertNull($eros->property('moid_jupiter'));
        self::assertSame(0, $eros->value('uncertainty_parameter'));
        self::assertSame(18679, $eros->value('observations'));
        self::assertSame(59, $eros->value('oppositions'));
        self::assertSame('1893-2026', $eros->value('arc'));
        self::assertEqualsWithDelta(643.19638953, $eros->property('orbital_period')->number(), 1e-6);
        self::assertFalse($eros->value('non_gravitational'));
        self::assertSame('mpc', $eros->property('eccentricity')->source);
    }

    public function testHalleyAComet(): void
    {
        $halley = $this->source()->entity(Identifier::of(Scheme::JPL_SBDB, '1P'));

        self::assertSame('1P/Halley', $halley->label);
        self::assertSame('comet', $halley->kind);
        self::assertContains('P/1682 Q1', $halley->synonyms);
        self::assertNull($halley->property('orbit_type'), 'the MPC\'s "Apollo" for a comet says nothing of it');
        self::assertNull($halley->property('semi_major_axis'), 'a comet\'s orbit is cometary only');
        self::assertEqualsWithDelta(0.572507655497257, $halley->property('perihelion_distance')->number(), 1e-12);
        self::assertEqualsWithDelta(162.1577743887316, $halley->property('inclination')->number(), 1e-12);
        self::assertSame('2061-08-14 01:25', $halley->value('perihelion_time'), 'MJD 74050.0594 in the MPC\'s elements: its next return');
        self::assertTrue($halley->value('non_gravitational'));
    }

    public function testAProvisionalDesignation(): void
    {
        $yr4 = $this->source()->entity('mpc:2024 YR4');

        self::assertSame('2024 YR4', $yr4->label);
        self::assertSame([], $yr4->synonyms);
        self::assertSame(['mpc:2024 YR4', 'jpl.sbdb:2024 YR4'], $yr4->identifiers()->keys());
        self::assertSame('Apollo', $yr4->value('orbit_type'));
        self::assertSame('0.00275 au', (string) $yr4->property('moid'));
        self::assertSame(1, $yr4->value('uncertainty_parameter'));
    }

    public function testANameOfSeveralOrNoneIsNull(): void
    {
        self::assertNull($this->source()->entity('Halley'), '1P/Halley and (2688) Halley');
        self::assertNull($this->source()->entity('zzzqqq'));
        self::assertSame(['query-identifier', 'query-identifier'], array_column($this->calls, 1), 'no orbit asked');
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach (['wikidata:Q16927', Identifier::of(Scheme::SIMBAD, 'M 31'), 'CHEBI:27732'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTheSearchOfNamesInPages(): void
    {
        $first = $this->source()->search(new Query('eros', limit: 5));

        self::assertSame([['ids' => ['%eros%'], 'comparison' => 'ILIKE']], array_column($this->calls, 2));
        self::assertSame(13, $first->total);
        self::assertSame('5', $first->next);
        self::assertSame('(433) Eros', $first->first()->label, 'the closest first');
        self::assertSame('mpc:433', (string) $first->first()->id);
        $labels = array_map(static fn ($e) => $e->label, $first->items);
        self::assertContains('(1943) Anteros', $labels);
        $last = $this->source()->search(new Query('eros', limit: 5, cursor: '10'));
        self::assertCount(3, $last);
        self::assertNull($last->next);
        self::assertCount(0, $this->source()->search(new Query('eros', Domain::CHEMISTRY)));
    }

    public function testTheLatestObservationsOutsideTheContract(): void
    {
        $observations = $this->source()->observations('2024 YR4', 3);

        self::assertSame(['GET', 'get-obs', ['desigs' => ['2024 YR4'], 'output_format' => ['OBS80']]], $this->calls[0]);
        self::assertCount(3, $observations);
        $last = $observations[0];
        self::assertInstanceOf(Observation::class, $last);
        self::assertSame('2026-02-26', $last->time->format('Y-m-d'));
        self::assertSame('274', $last->observatory, 'JWST');
        self::assertSame('space-based', $last->method);
        self::assertSame(30.3, $last->magnitude);
        self::assertSame('V', $last->band);
        self::assertEqualsWithDelta((14 + 26 / 60 + 7.12 / 3600) * 15, $last->ra, 1e-9);
        self::assertEqualsWithDelta(-(18 + 19 / 60 + 35.17 / 3600), $last->dec, 1e-9);
        self::assertGreaterThan($observations[1]->time, $last->time);
        self::assertCount(512, $this->source()->observations('2024 YR4', 1000), 'the satellites\' second lines left out');
    }

    public function testAnObs80Line(): void
    {
        $observation = Observation::fromObs80('     K24Y04R tC2024 12 25.30494112 43 47.961-33 57 29.55         17.3 GW~8eeHW68');
        self::assertSame('2024-12-25T07:19:06+00:00', $observation->time->format(\DATE_ATOM));
        self::assertSame('W68', $observation->observatory);
        self::assertSame('CCD', $observation->method);
        self::assertSame('G', $observation->band);
        self::assertNull(Observation::fromObs80('     K24Y04R 0s2026 02 26.8833171 -919752.013 +737465.892 +527814.845   ~9bJs274'));
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('<html><head><title>Internal Server Error</title></head></html>', ['http_code' => 500]))->entity('433');
            self::fail('a 500');
        } catch (UnavailableException $e) {
            self::assertSame(500, $e->status);
        }
        $this->expectException(ProviderException::class);
        $this->source(new MockResponse('{"error":[{"msg":"Input should be a valid dictionary"}]}', ['http_code' => 400]))->entity('433');
    }

    public function testTheFactory(): void
    {
        $factory = new MpcSourceFactory(new MockHttpClient());
        $source = $factory->create(['mailto' => 'support@glitchr.io']);
        self::assertSame('mpc', $factory->getName());
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $source->capabilities());
    }
}
