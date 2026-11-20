<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

/** Hvor versjonene hentes fra (GitHub Releases; en etterlignet kilde i testene). */
interface Utgivelseskilde
{
    /** @return list<Utgivelse> Versjonene av pakken (ikke utkast), nyeste først. */
    public function utgivelser(Pakke $p): array;

    /** Laster ned en ressurs (zip, sjekksum, signatur, versjon.json) til en fil, høyst $maks byte. */
    public function lastNed(Pakke $p, Utgivelse $u, string $ressurs, string $tilFil, int $maks): void;
}
