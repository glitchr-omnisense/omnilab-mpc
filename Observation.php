<?php

namespace Omnilab\Mpc;

/**
 * One optical observation of a body as the Minor Planet Center publishes
 * it (outside the contract): when, where on the sky (J2000 / ICRF right
 * ascension and declination, in degrees), how bright in which band, from
 * which observatory (its MPC code: 568 is Maunakea, C51 WISE), how it was
 * made (CCD, CMOS, photographic...), and the 80-column line it was read
 * from.
 */
final readonly class Observation
{
    private const METHODS = [
        'C' => 'CCD', 'B' => 'CMOS', 'P' => 'photographic', 'A' => 'photographic, reduced from B1950', 'E' => 'occultation', 'H' => 'Hipparcos',
        'M' => 'micrometer', 'T' => 'meridian or transit circle', 'X' => 'discovery observation, replaced', 'x' => 'replaced discovery observation',
        'V' => 'roving observer', 'S' => 'space-based', 'O' => 'offset', 'e' => 'encoder', 'n' => 'video frames', 'c' => 'corrected without republication',
    ];

    public function __construct(
        public \DateTimeImmutable $time,
        public float $ra,
        public float $dec,
        public ?float $magnitude,
        public ?string $band,
        public string $observatory,
        public ?string $method,
        public bool $discovery,
        public string $line,
    ) {
    }

    /**
     * An optical observation from a line of the MPC's 80-column format;
     * null for a radar's lines, a satellite's or a roving observer's
     * second line, and anything unreadable.
     */
    public static function fromObs80(string $line): ?self
    {
        $line = rtrim($line, "\r\n");
        if (\strlen($line) < 80) {
            return null;
        }
        $note = $line[14];
        if (\in_array($note, ['R', 'r', 's', 'v'], true)) {
            return null;
        }
        // "2024 12 25.304941": the day's fraction after the point.
        if (!preg_match('~^(\d{4}) (\d{2}) (\d{2})(\.\d+)?\s*$~', substr($line, 15, 17), $d)) {
            return null;
        }
        if (!preg_match('~^(\d{2}) (\d{2}) (\d{2}(?:\.\d*)?)\s*$~', substr($line, 32, 12), $r)
            || !preg_match('~^([+-])(\d{2}) (\d{2}) (\d{2}(?:\.\d*)?)\s*$~', substr($line, 44, 12), $c)) {
            return null;
        }
        $day = new \DateTimeImmutable(\sprintf('%s-%s-%s 00:00:00', $d[1], $d[2], $d[3]), new \DateTimeZone('UTC'));
        $seconds = (int) round((float) ('0'.($d[4] ?? '.0')) * 86400 * 1000);
        $time = $day->modify(\sprintf('+%d seconds', intdiv($seconds, 1000)))->modify(\sprintf('+%d milliseconds', $seconds % 1000));
        $magnitude = trim(substr($line, 65, 5));
        $band = trim($line[70]);

        return new self(
            $time,
            ((int) $r[1] + (int) $r[2] / 60 + (float) $r[3] / 3600) * 15,
            ('-' === $c[1] ? -1 : 1) * ((int) $c[2] + (int) $c[3] / 60 + (float) $c[4] / 3600),
            is_numeric($magnitude) ? (float) $magnitude : null,
            '' === $band ? null : $band,
            substr($line, 77, 3),
            self::METHODS[$note] ?? null,
            '*' === $line[12],
            $line,
        );
    }
}
