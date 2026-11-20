<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\Tester\Stotte\AppTestCase;
use Bivrost\Kjerne\Database\Felt;

/**
 * Kontoene ende til ende gjennom Kjernens HTTP-lag: registrering med godkjent e-postdomene eller klassekode,
 * bekreftelse av adressen, innlogging, glemt passord, klasser og administrasjonen.
 */
final class KontoTest extends AppTestCase
{
    public function testStudentMedGodkjentDomeneRegistrererBekrefterOgLoggerInn(): void
    {
        $this->godkjennDomene();
        $n = $this->nettleser('10.0.0.1');
        $n->start();
        [$svar, $j] = $n->kall('GET', '/api/oppsett');
        self::assertSame('Ark-Ivar', $j['appnavn']);
        self::assertSame('Sirevåg kommune', $j['virksomhet']);
        self::assertSame([['sted' => 'Høgskolen i Eksempel', 'domene' => 'hogskolen.example']], $j['steder']);

        // Adressen kan være under det godkjente domenet og skrives med store bokstaver.
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Kari Student', 'epost' => 'Kari@stud.hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(['steg' => 'bekreft'], $j);
        self::assertSame(1, $this->antallBrev('kari@stud.hogskolen.example'));

        // Før adressen er bekreftet: ingen innlogging, og bare riktig passord får vite hvorfor.
        [$svar, $j] = $n->kall('POST', '/api/innlogging', ['epost' => 'kari@stud.hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(403, $svar->status);
        self::assertSame('epost_ikke_bekreftet', $j['kode']);
        [$svar, $j] = $n->kall('POST', '/api/innlogging', ['epost' => 'kari@stud.hogskolen.example', 'passord' => 'feil passord her nå']);
        self::assertSame(401, $svar->status);
        self::assertSame('innlogging_feil', $j['kode']);

        [$svar, $j] = $n->kall('POST', '/api/registrering/bekreft', ['epost' => 'kari@stud.hogskolen.example', 'kode' => 'AAAA-BBBB']);
        self::assertSame(422, $svar->status);
        self::assertSame('kode_feil', $j['kode']);
        // Koden kan skrives med små bokstaver og uten bindestrek.
        $kode = strtolower(str_replace('-', '', $this->kodeFraEpost('kari@stud.hogskolen.example')));
        [$svar, $j] = $n->kall('POST', '/api/registrering/bekreft', ['epost' => 'kari@stud.hogskolen.example', 'kode' => $kode]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['innlogget']);
        self::assertSame('Kari Student', $j['innlogget']['navn']);
        self::assertSame('Student', $j['innlogget']['tittel']);
        self::assertSame([['urn' => 'urn:bivrost:app:arkivar:rolle:student', 'navn' => 'Student (Ark-Ivar)']], $j['innlogget']['tilganger']);

        [$svar, $j] = $n->kall('GET', '/api/meg');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['bruker']);
        self::assertSame('kari@stud.hogskolen.example', $j['bruker']['epost']);
        self::assertSame('student', $j['bruker']['rolle']);
        self::assertArrayNotHasKey('passord_hash', $j['bruker']);

        // Koden virker bare én gang.
        [$svar] = $this->nettleser('10.0.0.2')->kall('POST', '/api/registrering/bekreft', ['epost' => 'kari@stud.hogskolen.example', 'kode' => $kode]);
        self::assertSame(403, $svar->status, 'Uten CSRF-nøkkel avvises forespørselen.');
        $annen = $this->nettleser('10.0.0.2');
        $annen->start();
        [$svar] = $annen->kall('POST', '/api/registrering/bekreft', ['epost' => 'kari@stud.hogskolen.example', 'kode' => $kode]);
        self::assertSame(422, $svar->status);

        // Ut og inn igjen med passordet. Kjernens økt viser den innloggede.
        [$svar] = $n->kall('POST', '/bivrost/api/utlogging', []);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $n->kall('GET', '/api/meg');
        self::assertSame(401, $svar->status);
        $n->start();
        [$svar] = $n->kall('POST', '/api/innlogging', ['epost' => 'KARI@stud.hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar, $j] = $n->kall('GET', '/bivrost/api/okt');
        self::assertIsArray($j['innlogget']);
        self::assertSame('app', $j['innlogget']['metode']);
        self::assertIsArray($j['metoder']);
        self::assertTrue($j['metoder']['app']);
    }

    public function testUtenGodkjentDomeneKrevesKlassekode(): void
    {
        $drift = $this->godkjennDomene('hogskolen.example', false);
        $n = $this->nettleser('10.0.0.1');
        $n->start();
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Ola Utenfor', 'epost' => 'ola@annet.example', 'passord' => self::PASSORD]);
        self::assertSame(422, $svar->status);
        self::assertSame('ikke_godkjent_domene', $j['kode']);
        // Uten «underdomener» gjelder bare domenet selv.
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Ola Under', 'epost' => 'ola@stud.hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame('ikke_godkjent_domene', $j['kode']);
        // Et domene som bare slutter likt, er ikke godkjent.
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Ola Lik', 'epost' => 'ola@ikkehogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame('ikke_godkjent_domene', $j['kode']);
        self::assertSame([], $this->epost->brev());

        // Læreren lager en klasse, og koden gir rett til å registrere seg med en hvilken som helst adresse.
        $laerer = $this->ansatt($drift, 'laerer', 'lars@hogskolen.example', 'Lars Lærer', '10.0.0.9');
        [$svar, $j] = $laerer->kall('POST', '/api/klasser', ['navn' => 'Arkivkunnskap høst']);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['klasse']);
        $klasseId = Felt::tekst($j['klasse']['id']);
        $kode = Felt::tekst($j['klasse']['kode']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}$/', $kode);

        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Ola Utenfor', 'epost' => 'ola@annet.example', 'passord' => self::PASSORD, 'klassekode' => 'XXXX-XXXX']);
        self::assertSame('ukjent_klassekode', $j['kode']);
        $ola = $this->student('ola@annet.example', 'Ola Utenfor', strtolower($kode), '10.0.0.3');
        [$svar, $j] = $ola->kall('GET', '/api/meg');
        $klasser = self::rader($j['klasser']);
        self::assertCount(1, $klasser);
        self::assertSame('Arkivkunnskap høst', $klasser[0]['navn']);
        self::assertSame('Lars Lærer', $klasser[0]['laerer']);
        self::assertArrayNotHasKey('kode', $klasser[0], 'Studenten ser ikke koden på nytt.');

        // Læreren ser studenten. En annen lærer ser ikke klassen.
        [$svar, $j] = $laerer->kall('GET', "/api/klasser/$klasseId");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(['Ola Utenfor'], array_column(self::rader($j['studenter']), 'navn'));
        $annen = $this->ansatt($drift, 'laerer', 'lise@hogskolen.example', 'Lise Lærer', '10.0.0.8');
        [$svar] = $annen->kall('GET', "/api/klasser/$klasseId");
        self::assertSame(404, $svar->status);
        [$svar, $j] = $annen->kall('GET', '/api/klasser');
        self::assertSame([], $j['klasser']);
        // Studenten har ikke lærerens ruter.
        [$svar] = $ola->kall('GET', '/api/klasser');
        self::assertSame(403, $svar->status);

        // Ny kode: den gamle slutter å virke.
        [$svar, $j] = $laerer->kall('POST', "/api/klasser/$klasseId/ny-kode", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertNotSame($kode, $j['kode']);
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Per Sen', 'epost' => 'per@annet.example', 'passord' => self::PASSORD, 'klassekode' => $kode]);
        self::assertSame('ukjent_klassekode', $j['kode']);
    }

    public function testRegistreringRoperIkkeAtAdressenHarEnKonto(): void
    {
        $this->godkjennDomene();
        $kari = $this->student('kari@hogskolen.example');
        $n = $this->nettleser('10.0.0.5');
        $n->start();
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'En Annen', 'epost' => 'kari@hogskolen.example', 'passord' => 'et helt annet passord 9']);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(['steg' => 'bekreft'], $j, 'Samme svar som for en ny adresse.');
        $brev = $this->epost->brev();
        self::assertStringContainsString('Du har alt en konto', $brev[count($brev) - 1]['emne']);
        // Ingenting er endret: navnet og passordet er de samme.
        [$svar, $j] = $kari->kall('GET', '/api/meg');
        self::assertIsArray($j['bruker']);
        self::assertSame('Kari Student', $j['bruker']['navn']);
        [$svar] = $n->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => 'et helt annet passord 9']);
        self::assertSame(401, $svar->status);

        // Et svakt passord og en ugyldig adresse avvises med en forklaring.
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Nils Ny', 'epost' => 'nils@hogskolen.example', 'passord' => 'passord12345']);
        self::assertSame('passord_svakt', $j['kode']);
        [$svar, $j] = $n->kall('POST', '/api/registrering', ['navn' => 'Nils Ny', 'epost' => 'ikke en adresse', 'passord' => self::PASSORD]);
        self::assertSame('ugyldig_epost', $j['kode']);
    }

    public function testEnUbekreftetRegistreringKanGjoresPaNyttOgFaNyKode(): void
    {
        $this->godkjennDomene();
        $n = $this->nettleser('10.0.0.1');
        $n->start();
        $n->kall('POST', '/api/registrering', ['navn' => 'Feil Navn', 'epost' => 'kari@hogskolen.example', 'passord' => 'det første passordet 1']);
        $forste = $this->kodeFraEpost('kari@hogskolen.example');
        [$svar] = $n->kall('POST', '/api/registrering', ['navn' => 'Kari Student', 'epost' => 'kari@hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $n->kall('POST', '/api/registrering/bekreft', ['epost' => 'kari@hogskolen.example', 'kode' => $forste]);
        self::assertSame(422, $svar->status, 'Den første koden virker ikke etter en ny registrering.');
        [$svar] = $n->kall('POST', '/api/registrering/ny-kode', ['epost' => 'kari@hogskolen.example']);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $n->kall('POST', '/api/registrering/ny-kode', ['epost' => 'finnes-ikke@hogskolen.example']);
        self::assertSame(200, $svar->status, 'Samme svar for en adresse uten konto.');
        self::assertSame(0, $this->antallBrev('finnes-ikke@hogskolen.example'));

        // Koden virker i 30 minutter.
        $this->klokke->frem(1801);
        [$svar] = $n->kall('POST', '/api/registrering/bekreft', ['epost' => 'kari@hogskolen.example', 'kode' => $this->kodeFraEpost('kari@hogskolen.example')]);
        self::assertSame(422, $svar->status, 'Koden er utløpt.');
        $n->kall('POST', '/api/registrering/ny-kode', ['epost' => 'kari@hogskolen.example']);
        [$svar, $j] = $n->kall('POST', '/api/registrering/bekreft', ['epost' => 'kari@hogskolen.example', 'kode' => $this->kodeFraEpost('kari@hogskolen.example')]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['innlogget']);
        self::assertSame('Kari Student', $j['innlogget']['navn'], 'Navnet fra den siste registreringen gjelder.');
    }

    public function testGlemtPassordSetterNyttOgAvslutterAndreOkter(): void
    {
        $this->godkjennDomene();
        $gammel = $this->student('kari@hogskolen.example');
        $n = $this->nettleser('10.0.0.4');
        $n->start();
        [$svar] = $n->kall('POST', '/api/passord/glemt', ['epost' => 'ukjent@hogskolen.example']);
        self::assertSame(200, $svar->status, 'Samme svar for en adresse uten konto.');
        self::assertSame(0, $this->antallBrev('ukjent@hogskolen.example'));
        [$svar] = $n->kall('POST', '/api/passord/glemt', ['epost' => 'kari@hogskolen.example']);
        self::assertSame(200, $svar->status, $svar->kropp);
        $kode = $this->kodeFraEpost('kari@hogskolen.example');

        // Et svakt passord bruker ikke opp koden.
        [$svar, $j] = $n->kall('POST', '/api/passord/nytt', ['epost' => 'kari@hogskolen.example', 'kode' => $kode, 'passord' => 'kort']);
        self::assertSame('passord_svakt', $j['kode']);
        [$svar, $j] = $n->kall('POST', '/api/passord/nytt', ['epost' => 'kari@hogskolen.example', 'kode' => 'AAAA-BBBB', 'passord' => 'fire blå båter på fjorden']);
        self::assertSame('kode_feil', $j['kode']);
        [$svar, $j] = $n->kall('POST', '/api/passord/nytt', ['epost' => 'kari@hogskolen.example', 'kode' => $kode, 'passord' => 'fire blå båter på fjorden']);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['innlogget']);

        [$svar] = $gammel->kall('GET', '/api/meg');
        self::assertSame(401, $svar->status, 'Økten fra før passordet ble byttet, gjelder ikke lenger.');
        [$svar] = $n->kall('GET', '/api/meg');
        self::assertSame(200, $svar->status);
        $ny = $this->nettleser('10.0.0.6');
        $ny->start();
        [$svar] = $ny->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(401, $svar->status, 'Det gamle passordet virker ikke.');
        [$svar] = $ny->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => 'fire blå båter på fjorden']);
        self::assertSame(200, $svar->status, $svar->kropp);
    }

    public function testByttPassordKreverDetGamleOgAvslutterAndreOkter(): void
    {
        $this->godkjennDomene();
        $n = $this->student('kari@hogskolen.example');
        $annen = $this->nettleser('10.0.0.7');
        $annen->start();
        $annen->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => self::PASSORD]);
        [$svar, $j] = $n->kall('POST', '/api/meg/passord', ['gammelt' => 'feil gammelt passord', 'nytt' => 'fire blå båter på fjorden']);
        self::assertSame(403, $svar->status);
        self::assertSame('passord_feil', $j['kode']);
        [$svar] = $n->kall('POST', '/api/meg/passord', ['gammelt' => self::PASSORD, 'nytt' => 'fire blå båter på fjorden']);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $n->kall('GET', '/api/meg');
        self::assertSame(200, $svar->status, 'Økten som byttet passordet, fortsetter.');
        [$svar] = $annen->kall('GET', '/api/meg');
        self::assertSame(401, $svar->status, 'De andre øktene er avsluttet.');
    }

    public function testForMangeFeilInnloggingerStopperForsokene(): void
    {
        $this->godkjennDomene();
        $this->student('kari@hogskolen.example');
        $n = $this->nettleser('10.0.0.66');
        $n->start();
        for ($i = 0; $i < 5; $i++) {
            [$svar] = $n->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => 'feil passord nummer ' . $i]);
            self::assertSame(401, $svar->status);
        }
        [$svar, $j] = $n->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(429, $svar->status, 'Også riktig passord stoppes når grensen er nådd.');
        self::assertSame('for_mange_forsok', $j['kode']);
        self::assertNotNull($svar->hode('Retry-After'));
        $this->klokke->frem(901);
        [$svar] = $n->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(200, $svar->status, $svar->kropp);
    }

    public function testAdministratorStyrerRollerDomenerOgDeaktivering(): void
    {
        $drift = $this->godkjennDomene();
        $admin = $this->ansatt($drift, 'administrator', 'anna@hogskolen.example', 'Anna Admin', '10.0.0.20');
        $kari = $this->student('kari@hogskolen.example');

        // Studenten og den som ikke er logget inn, har ikke administrasjonen.
        [$svar] = $kari->kall('GET', '/api/admin/brukere');
        self::assertSame(403, $svar->status);
        [$svar] = $kari->kall('POST', '/api/admin/domener', ['domene' => 'annet.example', 'sted' => 'Et annet sted']);
        self::assertSame(403, $svar->status);
        $ukjent = $this->nettleser('10.0.0.30');
        $ukjent->start();
        [$svar] = $ukjent->kall('GET', '/api/admin/brukere');
        self::assertSame(401, $svar->status);

        [$svar, $j] = $admin->kall('GET', '/api/admin/brukere?sok=kari');
        self::assertSame(200, $svar->status, $svar->kropp);
        $brukere = self::rader($j['brukere']);
        self::assertCount(1, $brukere);
        $kariId = Felt::tekst($brukere[0]['id']);

        // Kari blir lærer: den gamle økten gjelder ikke lenger, og den nye rollen gjelder fra neste innlogging.
        [$svar] = $admin->kall('PUT', "/api/admin/brukere/$kariId", ['rolle' => 'laerer']);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $kari->kall('GET', '/api/meg');
        self::assertSame(401, $svar->status);
        $kari->start();
        [$svar, $j] = $kari->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => self::PASSORD]);
        self::assertIsArray($j['innlogget']);
        self::assertSame(['urn:bivrost:app:arkivar:rolle:student', 'urn:bivrost:app:arkivar:rolle:laerer'], array_column(self::rader($j['innlogget']['tilganger']), 'urn'));
        [$svar] = $kari->kall('GET', '/api/klasser');
        self::assertSame(200, $svar->status);

        // Deaktivert: ute med en gang, og ingen ny innlogging.
        [$svar] = $admin->kall('PUT', "/api/admin/brukere/$kariId", ['aktiv' => false]);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $kari->kall('GET', '/api/meg');
        self::assertSame(401, $svar->status);
        $kari->start();
        [$svar] = $kari->kall('POST', '/api/innlogging', ['epost' => 'kari@hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame(401, $svar->status);

        // Den siste administratoren kan ikke fjerne seg selv.
        [$svar, $j] = $admin->kall('GET', '/api/admin/brukere?sok=anna');
        $annaId = Felt::tekst(self::rader($j['brukere'])[0]['id']);
        [$svar, $j] = $admin->kall('PUT', "/api/admin/brukere/$annaId", ['rolle' => 'student']);
        self::assertSame(409, $svar->status);
        self::assertSame('siste_administrator', $j['kode']);

        // Domenene: endre, deaktivere og slette. Et deaktivert domene godkjenner ingen.
        [$svar, $j] = $admin->kall('GET', '/api/admin/domener');
        $domeneId = Felt::tekst(self::rader($j['domener'])[0]['id']);
        [$svar, $j] = $admin->kall('POST', '/api/admin/domener', ['domene' => '@Hogskolen.Example', 'sted' => 'Samme sted']);
        self::assertSame(409, $svar->status, 'Domenet finnes (uansett skrivemåte).');
        [$svar, $j] = $admin->kall('POST', '/api/admin/domener', ['domene' => 'ikke et domene', 'sted' => 'Feil']);
        self::assertSame('ugyldig_domene', $j['kode']);
        [$svar] = $admin->kall('PUT', "/api/admin/domener/$domeneId", ['sted' => 'Høgskolen i Eksempel', 'aktiv' => false]);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar, $j] = $ukjent->kall('POST', '/api/registrering', ['navn' => 'Nils Ny', 'epost' => 'nils@hogskolen.example', 'passord' => self::PASSORD]);
        self::assertSame('ikke_godkjent_domene', $j['kode']);
        [$svar] = $admin->kall('DELETE', "/api/admin/domener/$domeneId");
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar, $j] = $admin->kall('GET', '/api/admin/domener');
        self::assertSame([], $j['domener']);

        // Hendelsene står i loggen med klartekst.
        $hendelser = array_column($this->db->alle('SELECT hva FROM bivrost_logg'), 'hva');
        self::assertContains('urn:arkivar:hendelse:bruker:endret', $hendelser);
        self::assertContains('urn:arkivar:hendelse:domene:slettet', $hendelser);
        self::assertContains('urn:arkivar:hendelse:konto:registrert', $hendelser);
        $tekst = Felt::tekst($this->db->verdi("SELECT hendelse FROM bivrost_logg WHERE hva = 'urn:arkivar:hendelse:bruker:endret' ORDER BY nr LIMIT 1"));
        self::assertStringContainsString('Bruker endret', $tekst);
        self::assertStringNotContainsString(self::PASSORD, implode(' ', array_map(Felt::tekst(...), array_column($this->db->alle('SELECT hendelse FROM bivrost_logg'), 'hendelse'))));
    }
}
