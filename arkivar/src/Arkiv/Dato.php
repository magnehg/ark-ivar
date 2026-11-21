<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Psr\Clock\ClockInterface;

/**
 * Datoen i dag der kommunen holder til. Klokka i Kjerne går i UTC, men saksdato, journaldato og sjekken av at et
 * dokument ikke er datert frem i tid, følger norsk tid: ellers blir datoen feil den første timen etter midnatt.
 */
final class Dato
{
    private const TIDSSONE = 'Europe/Oslo';

    public static function idag(ClockInterface $klokke): string
    {
        return $klokke->now()->setTimezone(new \DateTimeZone(self::TIDSSONE))->format('Y-m-d');
    }

    /** Datoen i norsk tid for et tidspunkt lagret i databasen (UTC, slik Db::tid() skriver det). */
    public static function norsk(string $tidspunkt): string
    {
        return (new \DateTimeImmutable($tidspunkt, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TIDSSONE))->format('Y-m-d');
    }
}
