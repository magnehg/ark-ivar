<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Stotte;

use ArkIvar\App;
use ArkIvar\Epost\FilEpost;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Innlogging\Totp;
use Bivrost\Kjerne\Kjerne;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\TestDb;

/**
 * Grunnlag for testene av Ark-Ivar ende til ende: en ny database og installasjonsrot per test, Ark-Ivar installert
 * gjennom Kjernens installasjon (som på et webhotell), en klokke testen styrer, og e-post som skrives til fil.
 * Hver forespørsel går gjennom en ny Kjerne og App, slik det er i drift.
 */
abstract class AppTestCase extends DbTestCase
{
    protected const DRIFTSPASSORD = 'lang og god passfrase';

    protected string $rot = '';
    protected FastKlokke $klokke;
    protected FilEpost $epost;
    private string $totpHemmelighet = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rot = Mappe::ny();
        $this->klokke = new FastKlokke();
        $this->epost = new FilEpost($this->rot . '/drift/epost.jsonl');
        Env::skrivFil($this->rot . '/.env', TestDb::env($this->dbNavn) + [
            'GITHUB_API_URL' => 'http://127.0.0.1:9',
            'BIVROST_ADRESSE' => 'https://localhost',
            'BIVROST_KRYPTERINGSNOKKEL' => Hemmelighetsboks::nyNokkel(),
            'BIVROST_HMAC_NOKKEL' => 'hmac',
        ]);
        $this->installer();
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->rot);
        parent::tearDown();
    }

    /** Sender én forespørsel gjennom en ny Kjerne og App. */
    protected function handter(Foresporsel $f): Svar
    {
        $kjerne = new Kjerne(AppKonfig::fraListe(['rot' => $this->rot, 'webrot' => $this->rot . '/public'] + App::konfig()), $this->klokke);
        $ingenVenting = static function (): void {
        };
        $kjerne->settVent($ingenVenting);
        $app = new App($kjerne, $this->klokke, $this->epost, $ingenVenting);
        $svar = $kjerne->handter($f, $app->ruter(...));
        // Hver forespørsel har sin egen databasetilkobling. Den slippes her, så en test med mange forespørsler ikke
        // hoper dem opp (to kjøringer av testene samtidig gikk ellers tom for tilkoblinger).
        unset($kjerne, $app);
        gc_collect_cycles();

        return $svar;
    }

    /** En ny nettleser (egne informasjonskapsler). $ip skiller dem i grensene for forsøk. */
    protected function nettleser(string $ip = '127.0.0.1'): Nettleser
    {
        return new Nettleser($this->handter(...), $ip);
    }

    /** Installasjonen i nettleseren: den første driftsbrukeren med TOTP, så migreringene (Kjernens og appens). */
    private function installer(): void
    {
        $n = $this->nettleser();
        [$svar] = $n->kall('GET', '/bivrost/api/installasjon');
        self::assertSame(200, $svar->status, $svar->kropp);
        $kode = '';
        foreach ((array) file($this->rot . '/drift/installasjonskode.txt', FILE_IGNORE_NEW_LINES) as $linje) {
            if (is_string($linje) && $linje !== '' && !str_starts_with($linje, '#')) {
                $kode = $linje;
            }
        }
        $hode = ['x-bivrost-installasjonskode' => $kode];
        [$svar, $j] = $n->kall('POST', '/bivrost/api/installasjon/bruker', ['brukernavn' => 'drift', 'navn' => 'Drift Driftesen', 'passord' => self::DRIFTSPASSORD], $hode);
        self::assertSame(200, $svar->status, $svar->kropp);
        $this->totpHemmelighet = Felt::tekst($j['hemmelighet']);
        [$svar] = $n->kall('POST', '/bivrost/api/installasjon/fullfor', ['kode' => (new Totp($this->klokke))->kode($this->totpHemmelighet)], $hode);
        self::assertSame(200, $svar->status, $svar->kropp);
    }

    /** En nettleser der driftsbrukeren er logget inn med nødinnloggingen (passord og TOTP). */
    protected function drift(): Nettleser
    {
        $n = $this->nettleser('127.0.0.1');
        $n->start();
        [$svar] = $n->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => self::DRIFTSPASSORD]);
        self::assertSame(200, $svar->status, $svar->kropp);
        $this->klokke->frem(30);
        [$svar] = $n->kall('POST', '/bivrost/api/innlogging/lokal/totp', ['kode' => (new Totp($this->klokke))->kode($this->totpHemmelighet)]);
        self::assertSame(200, $svar->status, $svar->kropp);

        return $n;
    }

    protected const PASSORD = 'tre grønne sykler i regn';

    /** Legger til et godkjent domene som driftsbrukeren. */
    protected function godkjennDomene(string $domene = 'hogskolen.example', bool $underdomener = true): Nettleser
    {
        $drift = $this->drift();
        [$svar] = $drift->kall('POST', '/api/admin/domener', ['domene' => $domene, 'sted' => 'Høgskolen i Eksempel', 'underdomener' => $underdomener]);
        self::assertSame(201, $svar->status, $svar->kropp);

        return $drift;
    }

    /** Registrerer og bekrefter en student, og gir nettleseren der studenten er logget inn. */
    protected function student(string $epost, string $navn = 'Kari Student', ?string $klassekode = null, string $ip = '10.0.0.1'): Nettleser
    {
        $n = $this->nettleser($ip);
        $n->start();
        [$svar] = $n->kall('POST', '/api/registrering', ['navn' => $navn, 'epost' => $epost, 'passord' => self::PASSORD] + ($klassekode !== null ? ['klassekode' => $klassekode] : []));
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $n->kall('POST', '/api/registrering/bekreft', ['epost' => $epost, 'kode' => $this->kodeFraEpost(strtolower($epost))]);
        self::assertSame(200, $svar->status, $svar->kropp);

        return $n;
    }

    /** Lager en bruker som driftsbrukeren (administrator eller lærer) og logger den inn. */
    protected function ansatt(Nettleser $drift, string $rolle, string $epost, string $navn, string $ip): Nettleser
    {
        [$svar] = $drift->kall('POST', '/api/admin/brukere', ['epost' => $epost, 'navn' => $navn, 'rolle' => $rolle, 'passord' => self::PASSORD]);
        self::assertSame(201, $svar->status, $svar->kropp);
        $n = $this->nettleser($ip);
        $n->start();
        [$svar] = $n->kall('POST', '/api/innlogging', ['epost' => $epost, 'passord' => self::PASSORD]);
        self::assertSame(200, $svar->status, $svar->kropp);

        return $n;
    }

    /**
     * En liste med rader fra et JSON-svar (f.eks. «brukere» eller «klasser»), med typene testene trenger.
     *
     * @return list<array<string, mixed>>
     */
    protected static function rader(mixed $verdi): array
    {
        self::assertIsArray($verdi);
        $ut = [];
        foreach ($verdi as $rad) {
            self::assertIsArray($rad);
            $ny = [];
            foreach ($rad as $nokkel => $felt) {
                $ny[(string) $nokkel] = $felt;
            }
            $ut[] = $ny;
        }

        return $ut;
    }

    /** Koden i det siste brevet til adressen. */
    protected function kodeFraEpost(string $til): string
    {
        foreach (array_reverse($this->epost->brev()) as $brev) {
            if ($brev['til'] === $til && preg_match('/([0-9A-Z]{4}-[0-9A-Z]{4})/', $brev['tekst'], $m) === 1) {
                return $m[1];
            }
        }
        self::fail("Fant ingen kode i e-posten til $til.");
    }

    /** Antall brev til adressen. */
    protected function antallBrev(string $til): int
    {
        return count(array_filter($this->epost->brev(), static fn (array $b): bool => $b['til'] === $til));
    }
}
