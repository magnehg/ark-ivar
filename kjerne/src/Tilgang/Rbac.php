<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tilgang;

use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Okt\Innlogget;

/**
 * Tilgangssjekken i Kjerne (RBAC etter NIST/ANSI INCITS 359, BIV-ROL-01–06, ASVS V4). Rollene og tilgangene kommer
 * fra tokenet fra Bivrost Sentral (eller drift for nødinnloggingen) og sjekkes på serveren ved hver forespørsel.
 *
 *  - Bare URN-er som er aktive i rolleregisteret gir tilgang (en rolle appen har fjernet, gir ingenting).
 *  - Hierarki: brukeradministrator for alle apper gir brukeradministrator i hver app (og ingenting annet).
 *    Ingen fagrolle gir en tilgang, og ingen tilgang gir en fagrolle.
 *  - Statisk separasjon av oppgaver (SSD) fra AppKonfig «separasjon»: har brukeren flere av URN-ene i en regel enn
 *    regelen tillater, gir ingen av dem tilgang (feil på den trygge siden), og Kjerne logger bruddet.
 */
final class Rbac
{
    public function __construct(private readonly AppKonfig $app, private readonly Rolleregister $roller)
    {
    }

    /**
     * URN-ene som faktisk gir tilgang i denne appen.
     *
     * @param list<string> $urner
     * @return list<string>
     */
    public function effektive(array $urner): array
    {
        $ut = [];
        foreach ($urner as $u) {
            if (Urn::gjelderApp($u, $this->app->app) && $this->roller->aktiv($u)) {
                $ut[$u] = true;
            }
        }
        if (isset($ut[Urn::BRUKERADMINISTRATOR])) {
            $ut[Urn::appTilgang($this->app->app, 'brukeradministrator')] = true;
        }
        foreach ($this->brudd(array_keys($ut)) as $b) {
            foreach ($b['urner'] as $u) {
                unset($ut[$u]);
            }
        }

        return array_keys($ut);
    }

    /**
     * Reglene for separasjon av oppgaver som URN-ene bryter.
     *
     * @param list<string> $urner
     * @return list<array{navn: string, urner: list<string>}>
     */
    public function brudd(array $urner): array
    {
        $ut = [];
        foreach ($this->app->separasjon as $regel) {
            $har = array_values(array_intersect($regel['urner'], $urner));
            if (count($har) > $regel['maks']) {
                $ut[] = ['navn' => $regel['navn'], 'urner' => $har];
            }
        }

        return $ut;
    }

    public function har(?Innlogget $i, string $urn): bool
    {
        return $i !== null && in_array($urn, $this->effektive($i->urner), true);
    }

    /** Kaster 401 uten innlogging og 403 uten tilgangen. */
    public function krev(?Innlogget $i, string $urn): Innlogget
    {
        if ($i === null) {
            throw new HttpFeil(401, 'ikke_innlogget', 'bivrost.feil.tilgang.innlogging');
        }
        if (!$this->har($i, $urn)) {
            throw new HttpFeil(403, 'ingen_tilgang', 'bivrost.feil.tilgang.mangler');
        }

        return $i;
    }

    /**
     * Appens fagroller for brukeren (de som er aktive).
     *
     * @return list<string>
     */
    public function fagroller(?Innlogget $i): array
    {
        if ($i === null) {
            return [];
        }

        return array_values(array_filter($this->effektive($i->urner), static fn (string $u): bool => (Urn::tolk($u)['slag'] ?? null) === 'rolle'));
    }
}
