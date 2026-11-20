<?php

declare(strict_types=1);

namespace ArkIvar\Brukere;

use ArkIvar\Epost\EpostFeil;
use ArkIvar\Epost\Epostsender;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Innlogging\Forsok;
use Bivrost\Kjerne\Innlogging\Passordhash;
use Bivrost\Kjerne\Innlogging\Passordregler;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Okt\Innlogget;
use Bivrost\Kjerne\Okt\Okt;
use Bivrost\Kjerne\Sprak\Tekster;

/**
 * Kontoene i Ark-Ivar: registrering, bekreftelse av e-postadressen, innlogging, glemt passord og bytte av passord.
 *
 * Hvem kan registrere seg: den som har e-postadresse fra et godkjent sted (Domener), eller en klassekode fra en
 * lærer (Klasser). I begge tilfeller må adressen bekreftes med en kode som sendes dit, før kontoen kan brukes.
 *
 * Vern (det samme som i Kjernens nødinnlogging, og med den samme forsøkstabellen): samme svar og omtrent samme tid
 * for ukjent adresse og feil passord, grenser per IP-adresse, per adresse og per bruker, en forsinkelse ved feil, og
 * ny økt-ID ved innlogging. Registrering og «glemt passord» svarer likt enten adressen har en konto eller ikke, så
 * de ikke kan brukes til å finne ut hvem som er registrert.
 */
final class Konto
{
    /** Vinduet for grensene ved innlogging og koder (sekunder). */
    private const VINDU = 900;

    /** Vinduet for grensene på e-post som sendes (sekunder). */
    private const EPOSTVINDU = 3600;

    /** @var callable(): void */
    private $vent;

    /** @param (callable(): void)|null $vent Forsinkelsen ved feil (byttes ut i tester). */
    public function __construct(
        private readonly Brukere $brukere,
        private readonly Domener $domener,
        private readonly Klasser $klasser,
        private readonly Engangskoder $koder,
        private readonly Epostsender $epost,
        private readonly Forsok $forsok,
        private readonly Logg $logg,
        private readonly Tekster $tekster,
        private readonly string $appnavn,
        ?callable $vent = null,
    ) {
        $this->vent = $vent ?? static function (): void {
            usleep(random_int(250000, 750000));
        };
    }

    /**
     * Registrerer en ny bruker som student og sender koden som bekrefter e-postadressen. Finnes adressen fra før,
     * får eieren i stedet et brev om det, og svaret til nettleseren er det samme.
     */
    public function registrer(string $navn, string $epost, string $passord, ?string $klassekode, string $ip, string $sprak): void
    {
        $this->forsok->sjekk('arkivar_registrering', [[$ip, 10, self::EPOSTVINDU]]);
        $this->forsok->registrer('arkivar_registrering', $ip);
        $adresse = Brukere::normaliserEpost($epost);
        if ($adresse === null) {
            throw new HttpFeil(422, 'ugyldig_epost', 'arkivar.feil.epost');
        }
        $navn = trim($navn);
        if (mb_strlen($navn, 'UTF-8') < 2 || mb_strlen($navn, 'UTF-8') > 200) {
            throw new HttpFeil(422, 'ugyldig_navn', 'arkivar.feil.navn');
        }
        $klasse = null;
        if ($klassekode !== null && trim($klassekode) !== '') {
            $klasse = $this->klasser->medKode($klassekode);
            if ($klasse === null) {
                throw new HttpFeil(422, 'ukjent_klassekode', 'arkivar.feil.klassekode');
            }
        } elseif ($this->domener->godkjent($adresse) === null) {
            throw new HttpFeil(422, 'ikke_godkjent_domene', 'arkivar.feil.domene');
        }
        $feil = Passordregler::feil($passord, [$adresse, explode('@', $adresse)[0], $navn, $this->appnavn]);
        if ($feil !== null) {
            throw new HttpFeil(422, 'passord_svakt', $feil);
        }
        $this->forsok->sjekk('arkivar_epost', [[$adresse, 5, self::EPOSTVINDU]]);
        $this->forsok->registrer('arkivar_epost', $adresse);
        $via = $klasse !== null ? 'klasse' : 'domene';
        $rad = $this->brukere->finn($adresse);
        if ($rad !== null && $rad['epost_bekreftet'] !== null) {
            // Adressen har en konto. Eieren får vite at noen prøvde, og ingenting endres.
            $this->send($adresse, 'finnes', ['navn' => Felt::tekst($rad['navn'])], $sprak, true);
            $this->logg->skrivTrygt('urn:arkivar:hendelse:konto:registrering_avvist', $this->objekt($rad), ['grunn' => 'finnes', 'ip' => $ip]);

            return;
        }
        $hash = Passordhash::hash($passord);
        if ($rad === null) {
            $id = $this->brukere->opprett($adresse, $navn, $hash, Roller::STUDENT, $via, false);
        } else {
            $id = Felt::tekst($rad['id']);
            $this->brukere->oppdaterUbekreftet($id, $navn, $hash, $via);
        }
        if ($klasse !== null) {
            $this->klasser->meldInn(Felt::tekst($klasse['id']), $id);
        }
        $this->send($adresse, 'bekreft', ['navn' => $navn, 'kode' => $this->koder->ny($id, Engangskoder::BEKREFT)], $sprak);
        $this->logg->skriv(
            'urn:arkivar:hendelse:konto:registrert',
            new LoggObjekt(Roller::APP, 'bruker', $this->logg->tekst('arkivar.objekttype.bruker'), $id, $navn),
            [],
            [],
            ['via' => $via, 'klasse' => $klasse !== null ? Felt::tekst($klasse['navn']) : null, 'ip' => $ip],
            'sikkerhet',
        );
    }

    /** Bekrefter e-postadressen med koden fra brevet og logger brukeren inn. */
    public function bekreft(Okt $okt, string $epost, string $kode, string $ip): Innlogget
    {
        $adresse = Brukere::normaliserEpost($epost) ?? '';
        $this->forsok->sjekk('arkivar_kode_ip', [[$ip, 20, self::VINDU]]);
        $this->forsok->sjekk('arkivar_kode', [[$adresse, 5, self::VINDU]]);
        $rad = $adresse !== '' ? $this->brukere->finn($adresse) : null;
        $ok = $rad !== null && $rad['epost_bekreftet'] === null && Felt::tall($rad['aktiv']) === 1
            && $this->koder->bruk(Felt::tekst($rad['id']), Engangskoder::BEKREFT, $kode);
        if ($rad === null || !$ok) {
            $this->avvisKode($adresse, $ip, $rad, 'bekreft');
        }
        $id = Felt::tekst($rad['id']);
        $this->brukere->bekreftEpost($id);
        $this->forsok->nullstill('arkivar_kode', $adresse);
        $this->logg->skriv('urn:arkivar:hendelse:konto:bekreftet', $this->objekt($rad), [], [], ['ip' => $ip], 'sikkerhet');

        return $this->loggInnBruker($okt, $id, $ip, 'bekreftelse');
    }

    /** Sender en ny bekreftelseskode når adressen har en konto som ikke er bekreftet. Svarer alltid likt. */
    public function nyBekreftelseskode(string $epost, string $ip, string $sprak): void
    {
        $adresse = $this->epostMedGrense($epost, $ip);
        $rad = $adresse !== null ? $this->brukere->finn($adresse) : null;
        if ($adresse !== null && $rad !== null && $rad['epost_bekreftet'] === null && Felt::tall($rad['aktiv']) === 1) {
            $kode = $this->koder->ny(Felt::tekst($rad['id']), Engangskoder::BEKREFT);
            $this->send($adresse, 'bekreft', ['navn' => Felt::tekst($rad['navn']), 'kode' => $kode], $sprak, true);
        }
    }

    /**
     * Innlogging med e-postadresse og passord. En konto der adressen ikke er bekreftet, gir 403 med koden
     * «epost_ikke_bekreftet» (bare når passordet er riktig, så svaret ikke røper noe til andre).
     */
    public function loggInn(Okt $okt, string $epost, string $passord, string $ip): Innlogget
    {
        $adresse = Brukere::normaliserEpost($epost);
        $nokkel = $adresse ?? mb_strtolower(mb_substr($epost, 0, 64), 'UTF-8');
        $this->forsok->sjekk('arkivar_ip', [[$ip, 20, self::VINDU]]);
        $this->forsok->sjekk('arkivar_navn', [[$nokkel, 5, self::VINDU]]);
        $rad = $adresse !== null ? $this->brukere->finn($adresse) : null;
        if ($rad !== null) {
            $this->forsok->sjekk('arkivar_bruker', [[Felt::tekst($rad['id']), 5, self::VINDU]]);
        }
        // Like lang tid for ukjent adresse: passordet kontrolleres alltid mot en hash.
        $ok = Passordhash::stemmer($passord, $rad !== null ? Felt::tekst($rad['passord_hash']) : Passordhash::attrapp());
        if ($rad === null || !$ok || Felt::tall($rad['aktiv']) !== 1) {
            $this->forsok->registrer('arkivar_ip', $ip);
            $this->forsok->registrer('arkivar_navn', $nokkel);
            if ($rad !== null) {
                $this->forsok->registrer('arkivar_bruker', Felt::tekst($rad['id']));
            }
            $grunn = $rad === null ? 'ukjent' : (Felt::tall($rad['aktiv']) !== 1 ? 'inaktiv' : 'passord');
            $this->logg->skrivTrygt('innlogging:avvist', null, ['metode' => 'app', 'grunn' => $grunn, 'brukernavn' => mb_substr($nokkel, 0, 64), 'ip' => $ip]);
            ($this->vent)();
            throw new HttpFeil(401, 'innlogging_feil', 'arkivar.feil.innlogging');
        }
        $id = Felt::tekst($rad['id']);
        if (Passordhash::trengerNy(Felt::tekst($rad['passord_hash']))) {
            $this->brukere->settPassord($id, Passordhash::hash($passord), false);
        }
        if ($rad['epost_bekreftet'] === null) {
            throw new HttpFeil(403, 'epost_ikke_bekreftet', 'arkivar.feil.ikke_bekreftet');
        }
        $this->forsok->nullstill('arkivar_bruker', $id);

        return $this->loggInnBruker($okt, $id, $ip, 'passord');
    }

    /** Sender koden for nytt passord når adressen har en aktiv konto. Svarer alltid likt. */
    public function glemtPassord(string $epost, string $ip, string $sprak): void
    {
        $adresse = $this->epostMedGrense($epost, $ip);
        $rad = $adresse !== null ? $this->brukere->finn($adresse) : null;
        if ($adresse !== null && $rad !== null && Felt::tall($rad['aktiv']) === 1) {
            $kode = $this->koder->ny(Felt::tekst($rad['id']), Engangskoder::PASSORD);
            $this->send($adresse, 'passord', ['navn' => Felt::tekst($rad['navn']), 'kode' => $kode], $sprak, true);
            $this->logg->skrivTrygt('urn:arkivar:hendelse:konto:passordkode_sendt', $this->objekt($rad), ['ip' => $ip]);
        }
    }

    /**
     * Setter nytt passord med koden fra brevet, avslutter alle andre økter og logger brukeren inn. Koden viser at
     * brukeren leser e-posten, så adressen regnes også som bekreftet.
     */
    public function nyttPassord(Okt $okt, string $epost, string $kode, string $passord, string $ip): Innlogget
    {
        $adresse = Brukere::normaliserEpost($epost) ?? '';
        $this->forsok->sjekk('arkivar_kode_ip', [[$ip, 20, self::VINDU]]);
        $this->forsok->sjekk('arkivar_kode', [[$adresse, 5, self::VINDU]]);
        $rad = $adresse !== '' ? $this->brukere->finn($adresse) : null;
        if ($rad === null || Felt::tall($rad['aktiv']) !== 1 || !$this->koder->stemmer(Felt::tekst($rad['id']), Engangskoder::PASSORD, $kode)) {
            $this->avvisKode($adresse, $ip, $rad, 'passord');
        }
        $id = Felt::tekst($rad['id']);
        // Passordet kontrolleres før koden brukes opp, så et for svakt passord ikke koster brukeren koden.
        $feil = Passordregler::feil($passord, [$adresse, explode('@', $adresse)[0], Felt::tekst($rad['navn']), $this->appnavn]);
        if ($feil !== null) {
            throw new HttpFeil(422, 'passord_svakt', $feil);
        }
        if (!$this->koder->bruk($id, Engangskoder::PASSORD, $kode)) {
            $this->avvisKode($adresse, $ip, $rad, 'passord');
        }
        $this->brukere->settPassord($id, Passordhash::hash($passord), true);
        $this->brukere->bekreftEpost($id);
        $okt->avsluttFor('bruker', $id);
        $this->forsok->nullstill('arkivar_kode', $adresse);
        $this->forsok->nullstill('arkivar_bruker', $id);
        $this->logg->skriv('urn:arkivar:hendelse:konto:passord_nullstilt', $this->objekt($rad), [], [], ['ip' => $ip], 'sikkerhet');

        return $this->loggInnBruker($okt, $id, $ip, 'passordkode');
    }

    /** Bytte av eget passord: krever alltid det gamle, og avslutter alle andre økter. */
    public function byttPassord(Okt $okt, Innlogget $innlogget, string $gammelt, string $nytt, string $ip): void
    {
        $id = (string) $innlogget->aktor->id;
        $rad = $innlogget->metode === 'app' ? $this->brukere->hent($id) : null;
        if ($rad === null) {
            throw new HttpFeil(403, 'ingen_tilgang', 'bivrost.feil.tilgang.mangler');
        }
        $this->forsok->sjekk('arkivar_bruker', [[$id, 5, self::VINDU]]);
        if (!Passordhash::stemmer($gammelt, Felt::tekst($rad['passord_hash']))) {
            $this->forsok->registrer('arkivar_bruker', $id);
            $this->logg->skrivTrygt('innlogging:passord_avvist', $this->objekt($rad), ['ip' => $ip]);
            ($this->vent)();
            throw new HttpFeil(403, 'passord_feil', 'bivrost.feil.innlogging.gammelt_passord');
        }
        $adresse = Felt::tekst($rad['epost']);
        $feil = Passordregler::feil($nytt, [$adresse, explode('@', $adresse)[0], Felt::tekst($rad['navn']), $this->appnavn]);
        if ($feil !== null) {
            throw new HttpFeil(422, 'passord_svakt', $feil);
        }
        $this->brukere->settPassord($id, Passordhash::hash($nytt), true);
        $okt->avsluttFor('bruker', $id);
        $okt->loggInn(new Innlogget($innlogget->aktor, $innlogget->urner, 'app', $innlogget->tid, null, $innlogget->generasjon + 1, $innlogget->epost));
        $this->logg->skriv('innlogging:passord_byttet', $this->objekt($rad), [], [], ['ip' => $ip], 'sikkerhet');
    }

    /** Legger brukeren i økten (ny økt-ID) og fører innloggingen i loggen. */
    private function loggInnBruker(Okt $okt, string $id, string $ip, string $maate): Innlogget
    {
        $rad = $this->brukere->hent($id);
        if ($rad === null) {
            throw new HttpFeil(401, 'innlogging_feil', 'arkivar.feil.innlogging');
        }
        $innlogget = $this->brukere->innlogget($rad, $this->logg->tekst('arkivar.rolle.' . Felt::tekst($rad['rolle'])));
        $okt->loggInn($innlogget);
        $this->brukere->registrerInnlogging($id);
        $this->logg->skriv('innlogging:lykkes', $this->objekt($rad), [], [], ['metode' => 'app', 'maate' => $maate, 'ip' => $ip], 'sikkerhet', null, $innlogget->aktor);

        return $innlogget;
    }

    /**
     * Felles for brevene som sendes uten at brukeren er logget inn: grensen per IP-adresse og per adresse. Gir den
     * normaliserte adressen, eller null når den ikke er gyldig (svaret til nettleseren er likevel det samme).
     */
    private function epostMedGrense(string $epost, string $ip): ?string
    {
        $this->forsok->sjekk('arkivar_epost_ip', [[$ip, 10, self::EPOSTVINDU]]);
        $this->forsok->registrer('arkivar_epost_ip', $ip);
        $adresse = Brukere::normaliserEpost($epost);
        if ($adresse === null) {
            return null;
        }
        if ($this->forsok->antall('arkivar_epost', $adresse, self::EPOSTVINDU) >= 5) {
            // Grensen per adresse er stille: en feilmelding her ville røpet at adressen får brev.
            return null;
        }
        $this->forsok->registrer('arkivar_epost', $adresse);

        return $adresse;
    }

    /**
     * Feil eller utløpt kode: forsøket telles, føres i loggen og gir samme svar uansett grunn.
     *
     * @param array<string, mixed>|null $rad
     */
    private function avvisKode(string $adresse, string $ip, ?array $rad, string $formal): never
    {
        $this->forsok->registrer('arkivar_kode_ip', $ip);
        $this->forsok->registrer('arkivar_kode', $adresse);
        $this->logg->skrivTrygt('urn:arkivar:hendelse:konto:kode_avvist', $rad !== null ? $this->objekt($rad) : null, ['formal' => $formal, 'ip' => $ip]);
        ($this->vent)();
        throw new HttpFeil(422, 'kode_feil', 'arkivar.feil.kode');
    }

    /**
     * Sender et av brevene i språkfilene. Går det ikke, føres det i loggen. $stille: feilen meldes ikke til nettleseren, fordi svaret
     * ellers ville røpet at adressen har en konto (ny kode og glemt passord). Ved registrering får brukeren vite
     * at brevet ikke ble sendt.
     *
     * @param array<string, scalar|null> $verdier
     */
    private function send(string $til, string $brev, array $verdier, string $sprak, bool $stille = false): void
    {
        $verdier += ['appnavn' => $this->appnavn, 'minutter' => intdiv(Engangskoder::GYLDIG_SEKUNDER, 60)];
        try {
            // Brevene er «bekreft», «finnes» og «passord»: nøklene arkivar.epost.<brev>.emne og .tekst.
            $this->epost->send(
                $til,
                $this->tekster->tekst('arkivar.epost.' . $brev . '.emne', $verdier, $sprak),
                $this->tekster->tekst('arkivar.epost.' . $brev . '.tekst', $verdier, $sprak),
            );
        } catch (EpostFeil $feil) {
            $this->logg->skrivTrygt('urn:arkivar:hendelse:epost:feil', null, ['brev' => $brev, 'grunn' => $feil->getMessage()]);
            if (!$stille) {
                throw new HttpFeil(502, 'epost_feil', 'arkivar.feil.epost_sending');
            }
        }
    }

    /** @param array<string, mixed> $rad */
    private function objekt(array $rad): LoggObjekt
    {
        return new LoggObjekt(Roller::APP, 'bruker', $this->logg->tekst('arkivar.objekttype.bruker'), Felt::tekst($rad['id']), Felt::tekst($rad['navn']));
    }
}
