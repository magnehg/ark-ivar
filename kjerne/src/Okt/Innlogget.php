<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Okt;

use Bivrost\Kjerne\Logg\Aktor;

/**
 * Den som er logget inn i en økt: aktøren (navn og tittel i klartekst), URN-ene for rollene og tilgangene i denne
 * appen (fra tokenet fra Bivrost Sentral, eller drift for nødinnloggingen), metoden og når.
 *
 * Metodene: «oidc» (Bivrost Sentral), «lokal» (nødinnloggingen) og «app» (appens egne brukere, Ark-Ivars tillegg,
 * se Appbrukere).
 */
final class Innlogget
{
    /** @param list<string> $urner */
    public function __construct(
        public readonly Aktor $aktor,
        public readonly array $urner,
        public readonly string $metode,
        public readonly int $tid,
        public readonly ?string $sid = null,
        public readonly int $generasjon = 0,
        public readonly ?string $epost = null,
    ) {
        if (!in_array($metode, ['oidc', 'lokal', 'app'], true)) {
            throw new \InvalidArgumentException('Ukjent innloggingsmetode.');
        }
    }

    /** @return array<string, mixed> */
    public function tilListe(): array
    {
        return [
            'aktor' => ['type' => $this->aktor->type, 'id' => $this->aktor->id, 'navn' => $this->aktor->navn, 'tittel' => $this->aktor->tittel],
            'urner' => $this->urner, 'metode' => $this->metode, 'tid' => $this->tid, 'sid' => $this->sid,
            'generasjon' => $this->generasjon, 'epost' => $this->epost,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fraListe(array $d): ?self
    {
        $a = $d['aktor'] ?? null;
        if (!is_array($a) || !is_string($a['type'] ?? null) || !is_string($a['navn'] ?? null) || !is_string($d['metode'] ?? null)) {
            return null;
        }
        $urner = [];
        foreach (is_array($d['urner'] ?? null) ? $d['urner'] : [] as $u) {
            if (is_string($u)) {
                $urner[] = $u;
            }
        }
        try {
            return new self(
                new Aktor($a['type'], is_string($a['id'] ?? null) ? $a['id'] : null, $a['navn'], is_string($a['tittel'] ?? null) ? $a['tittel'] : null),
                $urner,
                $d['metode'],
                is_int($d['tid'] ?? null) ? $d['tid'] : 0,
                is_string($d['sid'] ?? null) ? $d['sid'] : null,
                is_int($d['generasjon'] ?? null) ? $d['generasjon'] : 0,
                is_string($d['epost'] ?? null) ? $d['epost'] : null,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
