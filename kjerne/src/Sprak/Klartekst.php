<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sprak;

use Bivrost\Kjerne\Tilgang\Rolleregister;
use Bivrost\Kjerne\Tilgang\Urn;

/**
 * Klartekst ved siden av koder (BIV-LOG-03, som B-027): alt Bivrost lagrer eller gir fra seg (logg, eksport,
 * API-svar) har klarteksten ved siden av hver kode, URN og ID, slik den var på tidspunktet, på installasjonens
 * hovedspråk. Ukjent kode gir koden selv som tekst, så ingenting står uten tekst.
 */
final class Klartekst
{
    public function __construct(
        private readonly Tekster $tekster,
        private readonly string $sprak,
        private readonly ?Rolleregister $roller = null,
    ) {
    }

    public function sprak(): string
    {
        return $this->sprak;
    }

    /** @param array<string, scalar|null> $verdier */
    public function tekst(string $nokkel, array $verdier = []): string
    {
        return $this->tekster->tekst($nokkel, $verdier, $this->sprak);
    }

    /**
     * {kode, tekst} fra en kodeliste i språkfilen: nøkkelen «<liste>.<kode>».
     *
     * @return array{kode: string, tekst: string}
     */
    public function kode(string $liste, string|int|bool $kode): array
    {
        $k = is_bool($kode) ? ($kode ? 'ja' : 'nei') : (string) $kode;
        $nokkel = "bivrost.$liste.$k";

        return ['kode' => $k, 'tekst' => $this->tekster->finnes($nokkel) ? $this->tekst($nokkel) : $k];
    }

    /**
     * {urn, navn} for en rolle eller tilgang, med appens navn («Appadministrator i Gerð», «Arkivar (Gerð)»).
     *
     * @return array{urn: string, navn: string}
     */
    public function urn(string $urn): array
    {
        $navn = $this->roller?->navn($urn, $this->sprak);
        if ($navn !== null) {
            return ['urn' => $urn, 'navn' => $navn];
        }
        $d = Urn::tolk($urn);

        return ['urn' => $urn, 'navn' => $d === null ? $urn : $d['navn']];
    }

    /**
     * @param list<string> $urner
     * @return list<array{urn: string, navn: string}>
     */
    public function urner(array $urner): array
    {
        return array_map(fn (string $u): array => $this->urn($u), $urner);
    }

    /**
     * {urn, tekst} for en hendelsestype urn:bivrost:hendelse:<område>:<handling> (eller appens egne).
     *
     * @return array{urn: string, tekst: string}
     */
    public function hendelse(string $urn): array
    {
        $deler = explode(':', $urn);
        $nokkel = ($deler[1] ?? '') . '.hendelse.' . implode('.', array_slice($deler, 3));

        return ['urn' => $urn, 'tekst' => $this->tekster->finnes($nokkel) ? $this->tekst($nokkel) : $urn];
    }
}
