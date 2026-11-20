<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Innlogging;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Logg\Aktor;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Okt\Innlogget;
use Bivrost\Kjerne\Okt\Okt;
use Bivrost\Kjerne\Tilgang\Urn;
use Psr\Clock\ClockInterface;

/**
 * Nødinnloggingen (BIV-AUT-04–06): en lokal driftsbruker logger inn med passord og deretter TOTP (eller en
 * gjenopprettingskode) og får bare tilgangen drift. Brukes når Sentral er nede, og til drift på et webhotell.
 *
 * Vern: samme svar og omtrent samme tid for ukjent bruker og feil passord; høyst 20 forsøk per IP-adresse og 5 per
 * brukernavn på 15 minutter før oppslaget, og 5 per bruker-ID etter oppslaget (også for TOTP-steget); en tilfeldig
 * forsinkelse ved feil; ny økt-ID ved innlogging; passordsteget alene gir ingen tilgang og varer høyst 5 minutter.
 *
 * En driftsbruker kan bytte sitt eget passord og sin egen TOTP (nyTotp og bekreftTotp, for eksempel etter en
 * innlogging med gjenopprettingskode fordi autentiseringsappen er tapt eller BIVROST_KRYPTERINGSNOKKEL er byttet).
 */
final class LokalInnlogging
{
    private const VINDU = 900;
    private const VENTER_SEKUNDER = 300;

    /** @var callable(): void */
    private $vent;

    /** @param (callable(): void)|null $vent Forsinkelsen ved feil (byttes ut i tester). */
    public function __construct(
        private readonly LokalBrukere $brukere,
        private readonly Forsok $forsok,
        private readonly Totp $totp,
        private readonly Logg $logg,
        private readonly ClockInterface $klokke,
        ?callable $vent = null,
    ) {
        $this->vent = $vent ?? static function (): void {
            usleep(random_int(250000, 750000));
        };
    }

    private function avvis(string $grunn, string $nokkel, ?string $brukerId, string $ip): never
    {
        $this->forsok->registrer('lokal_ip', $ip);
        $this->forsok->registrer('lokal_navn', $nokkel);
        if ($brukerId !== null) {
            $this->forsok->registrer('lokal_bruker', $brukerId);
        }
        $this->logg->skrivTrygt('innlogging:avvist', null, ['metode' => 'lokal', 'grunn' => $grunn, 'brukernavn' => mb_substr($nokkel, 0, 64), 'ip' => $ip]);
        ($this->vent)();
        throw new HttpFeil(401, 'innlogging_feil', 'bivrost.feil.innlogging.feil');
    }

    /** Steg 1: brukernavn og passord. Riktig gir steget TOTP (i økten), ingen tilgang ennå. */
    public function passord(Okt $okt, string $brukernavn, string $passord, string $ip): void
    {
        $navn = LokalBrukere::normaliser($brukernavn);
        $nokkel = $navn ?? mb_strtolower(mb_substr($brukernavn, 0, 64), 'UTF-8');
        $this->forsok->sjekk('lokal_ip', [[$ip, 20, self::VINDU]]);
        $this->forsok->sjekk('lokal_navn', [[$nokkel, 5, self::VINDU]]);
        $b = $navn !== null ? $this->brukere->finn($navn) : null;
        if ($b !== null) {
            $this->forsok->sjekk('lokal_bruker', [[Felt::tekst($b['id']), 5, self::VINDU]]);
        }
        // Like lang tid for ukjent bruker: passordet kontrolleres alltid mot en hash.
        $ok = Passordhash::stemmer($passord, $b !== null ? Felt::tekst($b['passord_hash']) : Passordhash::attrapp());
        if ($b === null || !$ok || Felt::tall($b['aktiv']) !== 1) {
            $this->avvis($b === null ? 'ukjent' : (Felt::tall($b['aktiv']) !== 1 ? 'inaktiv' : 'passord'), $nokkel, $b !== null ? Felt::tekst($b['id']) : null, $ip);
        }
        if (Passordhash::trengerNy(Felt::tekst($b['passord_hash']))) {
            $this->brukere->settPassord(Felt::tekst($b['id']), Passordhash::hash($passord), false);
        }
        $okt->roter();
        $okt->sett('lokal_venter', ['id' => Felt::tekst($b['id']), 'til' => $this->klokke->now()->getTimestamp() + self::VENTER_SEKUNDER]);
    }

    /** Steg 2: TOTP-kode eller gjenopprettingskode. Gir den innloggede (med drift). */
    public function totp(Okt $okt, string $kode, string $ip): Innlogget
    {
        $v = $okt->hent('lokal_venter');
        if (!is_array($v) || !is_string($v['id'] ?? null) || !is_int($v['til'] ?? null) || $v['til'] < $this->klokke->now()->getTimestamp()) {
            $okt->fjern('lokal_venter');
            throw new HttpFeil(401, 'innlogging_utlopt', 'bivrost.feil.innlogging.utlopt');
        }
        $id = $v['id'];
        $this->forsok->sjekk('lokal_ip', [[$ip, 20, self::VINDU]]);
        $this->forsok->sjekk('lokal_totp', [[$id, 5, self::VINDU]]);
        $b = $this->brukere->hent($id);
        if ($b === null || Felt::tall($b['aktiv']) !== 1) {
            $okt->fjern('lokal_venter');
            throw new HttpFeil(401, 'innlogging_feil', 'bivrost.feil.innlogging.feil');
        }
        $metode = 'totp';
        // Er BIVROST_KRYPTERINGSNOKKEL byttet, kan hemmeligheten ikke dekrypteres. Da virker gjenopprettingskodene
        // fortsatt (de er SHA-256), og brukeren kan sette ny TOTP etter innloggingen (nyTotp). Ingen 500.
        $lesbar = $this->brukere->totpLesbar($b);
        $steg = $lesbar ? $this->totp->kontroller($this->brukere->totpHemmelighet($b), $kode, is_numeric($b['totp_siste_steg']) ? Felt::tall($b['totp_siste_steg']) : null) : null;
        $ok = $steg !== null && $this->brukere->brukSteg($id, $steg);
        if (!$ok && preg_match('/^[0-9A-Za-z]{4}(-?[0-9A-Za-z]{4}){3}$/D', trim($kode)) === 1) {
            $ok = $this->brukere->brukGjenopprettingskode($id, $kode);
            $metode = 'gjenopprettingskode';
        }
        if (!$ok) {
            $this->forsok->registrer('lokal_totp', $id);
            $this->forsok->registrer('lokal_ip', $ip);
            $this->logg->skrivTrygt('innlogging:avvist', $this->objekt($b), ['metode' => 'lokal', 'grunn' => $lesbar ? 'totp' : 'totp_ulesbar', 'ip' => $ip]);
            ($this->vent)();
            // Brukeren har alt vist passordet. Å si at TOTP ikke kan leses, gir ingen ny hjelp til en angriper (bare
            // gjenopprettingskodene virker uansett), men forteller driften hva som er galt.
            throw $lesbar
                ? new HttpFeil(401, 'innlogging_feil', 'bivrost.feil.innlogging.totp')
                : new HttpFeil(401, 'totp_ulesbar', 'bivrost.feil.innlogging.totp_ulesbar');
        }
        $this->forsok->nullstill('lokal_bruker', $id);
        $this->forsok->nullstill('lokal_totp', $id);
        $aktor = Aktor::lokal($id, Felt::tekst($b['navn']), is_string($b['tittel']) ? $b['tittel'] : null);
        $i = new Innlogget($aktor, [Urn::DRIFT], 'lokal', $this->klokke->now()->getTimestamp(), null, Felt::tall($b['generasjon']));
        $okt->loggInn($i);
        $this->logg->skriv('innlogging:lykkes', $this->objekt($b), [], [], ['metode' => 'lokal', 'andre_faktor' => $metode, 'ip' => $ip,
            'gjenstaende_koder' => $metode === 'gjenopprettingskode' ? $this->brukere->gjenstaendeKoder($id) : null, 'totp_lesbar' => $lesbar], 'sikkerhet', null, $aktor);

        return $i;
    }

    /** Bytte av eget passord: krever alltid det gamle (også for administratorer), avslutter alle andre økter. */
    public function byttPassord(Okt $okt, Innlogget $i, string $gammelt, string $nytt, string $ip): void
    {
        $id = (string) $i->aktor->id;
        $b = $this->brukere->hent($id);
        if ($b === null || $i->metode !== 'lokal') {
            throw new HttpFeil(403, 'ingen_tilgang', 'bivrost.feil.tilgang.mangler');
        }
        $this->forsok->sjekk('lokal_bruker', [[$id, 5, self::VINDU]]);
        if (!Passordhash::stemmer($gammelt, Felt::tekst($b['passord_hash']))) {
            $this->forsok->registrer('lokal_bruker', $id);
            $this->logg->skrivTrygt('innlogging:passord_avvist', $this->objekt($b), ['ip' => $ip]);
            ($this->vent)();
            throw new HttpFeil(403, 'passord_feil', 'bivrost.feil.innlogging.gammelt_passord');
        }
        $feil = Passordregler::feil($nytt, [Felt::tekst($b['brukernavn']), Felt::tekst($b['navn'])]);
        if ($feil !== null) {
            throw new HttpFeil(422, 'passord_svakt', $feil);
        }
        $this->brukere->settPassord($id, Passordhash::hash($nytt), true);
        $okt->avsluttFor('lokal', $id);
        $ny = new Innlogget($i->aktor, $i->urner, 'lokal', $i->tid, null, $i->generasjon + 1);
        $okt->loggInn($ny);
        $this->logg->skriv('innlogging:passord_byttet', $this->objekt($b), [], [], ['ip' => $ip], 'sikkerhet');
    }

    /**
     * Steg 1 av bytte av TOTP (BIV-AUT-05): krever passordet på nytt (ny autentisering før en faktor byttes, ASVS
     * 2.5/6.4), lager en ny hemmelighet og legger den kryptert i økten i 5 minutter. Den gamle hemmeligheten virker
     * til den nye er bekreftet. Gir adressen til QR-koden og hemmeligheten (vises én gang).
     *
     * @return array{otpauth: string, hemmelighet: string}
     */
    public function nyTotp(Okt $okt, Innlogget $i, string $passord, string $utsteder, string $ip): array
    {
        $b = $this->egenLokalBruker($i);
        $id = Felt::tekst($b['id']);
        $this->forsok->sjekk('lokal_bruker', [[$id, 5, self::VINDU]]);
        if (!Passordhash::stemmer($passord, Felt::tekst($b['passord_hash']))) {
            $this->forsok->registrer('lokal_bruker', $id);
            $this->logg->skrivTrygt('innlogging:totp_avvist', $this->objekt($b), ['grunn' => 'passord', 'ip' => $ip]);
            ($this->vent)();
            throw new HttpFeil(403, 'passord_feil', 'bivrost.feil.innlogging.gammelt_passord');
        }
        $hemmelighet = $this->totp->nyHemmelighet();
        $okt->sett('lokal_ny_totp', [
            'id' => $id,
            'hemmelighet' => $this->brukere->pakkInnVentendeTotp($id, $hemmelighet),
            'til' => $this->klokke->now()->getTimestamp() + self::VENTER_SEKUNDER,
        ]);

        return ['otpauth' => $this->totp->adresse($hemmelighet, $utsteder, Felt::tekst($b['brukernavn'])), 'hemmelighet' => $hemmelighet];
    }

    /**
     * Steg 2 av bytte av TOTP: en kode fra den nye hemmeligheten viser at autentiseringsappen har den. Da lagres den,
     * alle andre økter for brukeren avsluttes, og det lages ti nye gjenopprettingskoder (de gamle kan være brukt eller
     * kjent; de nye vises én gang). Grensen for forsøk er den samme som for TOTP ved innloggingen.
     *
     * @return list<string> De nye gjenopprettingskodene.
     */
    public function bekreftTotp(Okt $okt, Innlogget $i, string $kode, string $ip): array
    {
        $b = $this->egenLokalBruker($i);
        $id = Felt::tekst($b['id']);
        $v = $okt->hent('lokal_ny_totp');
        $hemmelighet = is_array($v) && ($v['id'] ?? null) === $id && is_string($v['hemmelighet'] ?? null) && is_int($v['til'] ?? null)
            && $v['til'] >= $this->klokke->now()->getTimestamp()
            ? $this->brukere->pakkUtVentendeTotp($id, $v['hemmelighet'])
            : null;
        if ($hemmelighet === null) {
            $okt->fjern('lokal_ny_totp');
            throw new HttpFeil(409, 'totp_ikke_startet', 'bivrost.feil.innlogging.totp_ikke_startet');
        }
        $this->forsok->sjekk('lokal_totp', [[$id, 5, self::VINDU]]);
        $steg = $this->totp->kontroller($hemmelighet, $kode, null);
        if ($steg === null) {
            $this->forsok->registrer('lokal_totp', $id);
            $this->logg->skrivTrygt('innlogging:totp_avvist', $this->objekt($b), ['grunn' => 'kode', 'ip' => $ip]);
            ($this->vent)();
            throw new HttpFeil(422, 'totp', 'bivrost.feil.innlogging.totp');
        }
        $this->brukere->settTotp($id, $hemmelighet, $steg);
        $koder = $this->brukere->nyeGjenopprettingskoder($id);
        $okt->fjern('lokal_ny_totp');
        $this->forsok->nullstill('lokal_totp', $id);
        $okt->avsluttFor('lokal', $id);
        $okt->loggInn(new Innlogget($i->aktor, $i->urner, 'lokal', $i->tid, null, $i->generasjon + 1));
        $this->logg->skriv('innlogging:totp_byttet', $this->objekt($b), [], [], ['ip' => $ip, 'gjenopprettingskoder' => count($koder)], 'sikkerhet');

        return $koder;
    }

    /**
     * Den lokale driftsbrukeren som er logget inn. Bare en lokal innlogging kan endre sin egen TOTP; en bruker fra
     * Sentral har ingen lokal TOTP.
     *
     * @return array<string, mixed>
     */
    private function egenLokalBruker(Innlogget $i): array
    {
        $b = $i->metode === 'lokal' ? $this->brukere->hent((string) $i->aktor->id) : null;
        if ($b === null || Felt::tall($b['aktiv']) !== 1) {
            throw new HttpFeil(403, 'ingen_tilgang', 'bivrost.feil.tilgang.mangler');
        }

        return $b;
    }

    /** @param array<string, mixed> $b */
    private function objekt(array $b): LoggObjekt
    {
        return new LoggObjekt(null, 'lokal_bruker', $this->logg->tekst('bivrost.objekttype.lokal_bruker'), Felt::tekst($b['id']), Felt::tekst($b['navn']));
    }
}
