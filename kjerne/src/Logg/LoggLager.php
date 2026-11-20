<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * Lagringen av en logg i det felles formatet (BIV-LOG-04). Bivrost bruker DbLoggLager for sin egen logg; en app kan
 * bruke den samme klassen med sin egen tabell for fagloggen, eller lage sin egen lagring. Grensesnittet har med vilje
 * ingen metode som endrer en hendelse: loggen er bare tillegg.
 */
interface LoggLager
{
    public function skriv(Hendelse $h): void;

    /**
     * Nyeste først. $forId gir neste side (hendelser eldre enn den med denne løpenummer-ID-en).
     *
     * @return list<array{nr: int, hendelse: Hendelse}>
     */
    public function les(LoggFilter $filter, int $antall = 200, ?int $forNr = null): array;

    /**
     * Går gjennom alle hendelsene med filteret, eldste først, uten å lese alt inn i minnet.
     *
     * @param callable(Hendelse): void $hver
     */
    public function hverEn(LoggFilter $filter, callable $hver): int;

    /** Sletter sikkerhetsloggen som er eldre enn $dager. Rører aldri revisjonsloggen. Gir antall slettet. */
    public function ryddSikkerhetslogg(int $dager): int;
}
