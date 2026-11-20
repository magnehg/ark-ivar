<?php

declare(strict_types=1);

namespace Bivrost\Kjerne;

use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Logg\Aktor;

/**
 * Tilstanden for én forespørsel: korrelasjons-ID, kilde (nett, api, kommandolinje, system) og hvem som handler.
 * Lages på nytt for hver forespørsel og deles aldri mellom forespørsler.
 */
final class Kontekst
{
    public const KILDER = ['nett', 'api', 'kommandolinje', 'system'];

    private string $korrelasjon;

    public function __construct(
        private Aktor $aktor,
        private string $kilde = 'nett',
        ?string $korrelasjon = null,
    ) {
        if (!in_array($kilde, self::KILDER, true)) {
            throw new \InvalidArgumentException('Ukjent kilde.');
        }
        $this->korrelasjon = $korrelasjon !== null && Tilfeldig::erUuid($korrelasjon) ? $korrelasjon : Tilfeldig::uuid();
    }

    public function korrelasjon(): string
    {
        return $this->korrelasjon;
    }

    /** Fortsetter en korrelasjon fra et tidligere kall. En ugyldig ID overses. */
    public function fortsettKorrelasjon(?string $id): void
    {
        if ($id !== null && Tilfeldig::erUuid($id)) {
            $this->korrelasjon = $id;
        }
    }

    public function aktor(): Aktor
    {
        return $this->aktor;
    }

    public function settAktor(Aktor $aktor): void
    {
        $this->aktor = $aktor;
    }

    public function kilde(): string
    {
        return $this->kilde;
    }

    public function settKilde(string $kilde): void
    {
        if (!in_array($kilde, self::KILDER, true)) {
            throw new \InvalidArgumentException('Ukjent kilde.');
        }
        $this->kilde = $kilde;
    }
}
