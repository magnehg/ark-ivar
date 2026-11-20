<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Mappene studenten oppretter selv. Mappen opprettes først, gjerne fra en saksmal, og dokumentene registreres i den
 * etterpå (Postmottak::journalpost). En mappe kan stå tom, og en tom mappe studenten har opprettet, kan slettes.
 * Mappene som fulgte med arkivet, kan ikke slettes.
 */
final class Mapper
{
    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke,
        private readonly Grunndata $grunndata,
        private readonly Register $register,
    ) {
    }

    /**
     * Oppretter en mappe fra skjemaet og gir ID-en.
     *
     * @param array<string, mixed> $inn JSON-kroppen: mal, tittel, arkivdel, klasse, enhet, skjerm_tittel,
     *                                  offentlig_tittel, skjerm_klasse og tilgangskode.
     */
    public function opprett(string $arkivId, array $inn): string
    {
        $tittel = trim(Felt::tekst($inn['tittel'] ?? null));
        $lengde = mb_strlen($tittel, 'UTF-8');
        if ($lengde < 3 || $lengde > 500) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.sakstittel');
        }
        if (preg_match('/\[[^\]]*\]/u', $tittel) === 1) {
            // Forslaget fra saksmalen har hakeparenteser der studenten skal skrive det som gjelder saken.
            throw new HttpFeil(422, 'tittel_ikke_utfylt', 'arkivar.feil.tittel_ikke_utfylt');
        }
        $mal = trim(Felt::tekst($inn['mal'] ?? null));
        if ($mal !== '' && !$this->grunndata->harSaksmal($mal)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.saksmal');
        }
        $arkivdel = Felt::tekst($inn['arkivdel'] ?? null);
        if (!$this->grunndata->harArkivdel($arkivdel)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.arkivdel');
        }
        $klasse = Felt::tekst($inn['klasse'] ?? null);
        if (!$this->grunndata->harKlasse($arkivdel, $klasse)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.klasse');
        }
        $enhet = Felt::tekst($inn['enhet'] ?? null);
        if (!$this->grunndata->harEnhet($enhet)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.enhet');
        }
        $tilgangskode = trim(Felt::tekst($inn['tilgangskode'] ?? null));
        if ($tilgangskode !== '' && !$this->grunndata->harTilgangskode($tilgangskode)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.tilgangskode');
        }
        $skjermTittel = ($inn['skjerm_tittel'] ?? false) === true;
        $skjermKlasse = ($inn['skjerm_klasse'] ?? false) === true;
        if (($skjermTittel || $skjermKlasse) && $tilgangskode === '') {
            // Skjerming i journalen må ha en hjemmel, og hjemmelen følger tilgangskoden.
            throw new HttpFeil(422, 'skjerming_uten_hjemmel', 'arkivar.feil.skjerming_uten_hjemmel');
        }
        $offentlig = trim(Felt::tekst($inn['offentlig_tittel'] ?? null));
        if (mb_strlen($offentlig, 'UTF-8') > 500) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.offentlig_tittel');
        }

        // Saksnummeret tas fra telleren på arkivet. I en transaksjon med lås kan ikke to mapper få samme nummer.
        return $this->db->transaksjon(function () use ($arkivId, $tittel, $arkivdel, $klasse, $enhet, $skjermTittel, $offentlig, $tilgangskode, $skjermKlasse, $mal): string {
            $this->register->laas($arkivId);

            return $this->register->opprettSak(
                $arkivId,
                $tittel,
                $arkivdel,
                $klasse,
                $enhet,
                $this->grunndata->forsteIEnhet($enhet),
                Dato::idag($this->klokke),
                null,
                $skjermTittel ? ($offentlig !== '' ? $offentlig : '*****') : null,
                $tilgangskode !== '' ? $tilgangskode : null,
                Kodelister::SAK_UNDER_BEHANDLING,
                $skjermKlasse,
                $mal !== '' ? $mal : null,
            );
        });
    }

    /** Sletter en tom mappe studenten har opprettet. */
    public function slett(string $arkivId, string $sakId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $sakId): void {
            // Samme lås som registreringen bruker: et dokument kan ikke bli registrert i mappen mens den slettes.
            $this->register->laas($arkivId);
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if ($sak['nokkel'] !== null) {
                throw new HttpFeil(409, 'mappe_fulgte_med', 'arkivar.feil.mappe_fulgte_med');
            }
            if (!$this->register->slettTomSak($arkivId, $sakId)) {
                throw new HttpFeil(409, 'mappe_ikke_tom', 'arkivar.feil.mappe_ikke_tom');
            }
        });
    }
}
