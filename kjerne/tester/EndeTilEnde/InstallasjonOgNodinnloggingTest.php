<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\EndeTilEnde;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Innlogging\Totp;
use Bivrost\Kjerne\Kjerne;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Oppdatering\Signatur;
use Bivrost\Kjerne\Oppdatering\Utgivernokler;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\Skjema;
use Bivrost\Kjerne\Tester\Stotte\TestDb;

/**
 * Installasjonen i nettleseren og nødinnloggingen ende til ende gjennom Kjernens HTTP-lag (forespørsel inn, svar ut,
 * informasjonskapsler og CSRF), som et webhotell uten kommandolinje.
 */
final class InstallasjonOgNodinnloggingTest extends DbTestCase
{
    private string $rot = '';
    private FastKlokke $klokke;

    /** @var array<string, string> */
    private array $kapsler = [];

    private string $csrf = '';

    /** Utgiverens offentlige nøkkel, som følger med de installerte filene til Kjerne. */
    private string $utgiver = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rot = Mappe::ny();
        mkdir("$this->rot/app/migrasjoner", 0755, true);
        file_put_contents("$this->rot/app/migrasjoner/001_app.sql", 'CREATE TABLE IF NOT EXISTS app_ting (id INT PRIMARY KEY, navn VARCHAR(100));');
        // Kjerne er pakket ut i bivrost/kjerne/ med utgiverens offentlige nøkkel (BIV-DRF-14).
        mkdir("$this->rot/bivrost/kjerne", 0755, true);
        $this->utgiver = Signatur::nyttNokkelpar()['offentlig'];
        file_put_contents("$this->rot/bivrost/kjerne/signeringsnokkel.offentlig", $this->utgiver . "\n");
        // .env fra webhotellet med en ugyldig GitHub-adresse, så testen aldri går mot nettet.
        Env::skrivFil("$this->rot/.env", ['GITHUB_API_URL' => 'http://127.0.0.1:9', 'BIVROST_TILLAT_HTTP_LOKALT' => '1']);
        $this->klokke = new FastKlokke();
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->rot);
        parent::tearDown();
    }

    private function kjerne(): Kjerne
    {
        $k = new Kjerne(AppKonfig::fraListe([
            'app' => 'testapp', 'appnavn' => 'Testappen', 'rot' => $this->rot, 'migreringer' => "$this->rot/app/migrasjoner",
            'tabellprefikser' => ['app_'], 'roller' => [['navn' => 'leser', 'tekst' => 'Leser']],
        ]), $this->klokke);
        $k->settVent(static function (): void {
        });

        return $k;
    }

    /**
     * @param array<string, mixed>|null $kropp
     * @param array<string, string> $hoder
     * @return array{0: Svar, 1: array<string, mixed>}
     */
    private function kall(string $metode, string $sti, ?array $kropp = null, array $hoder = [], string $vert = 'localhost', bool $medCsrf = true, string $ip = '127.0.0.1'): array
    {
        $h = $hoder + ($kropp !== null ? ['content-type' => 'application/json'] : []) + ($medCsrf && $this->csrf !== '' ? ['x-csrf' => $this->csrf] : []);
        $query = [];
        if (str_contains($sti, '?')) {
            [$sti, $q] = explode('?', $sti, 2);
            parse_str($q, $qq);
            foreach ($qq as $qk => $qv) {
                $query[(string) $qk] = is_string($qv) ? $qv : '';
            }
        }
        $s = $this->kjerne()->handter(new Foresporsel($metode, $sti, $h, $query, $this->kapsler, $kropp === null ? '' : (string) json_encode($kropp), $ip, false, $vert));
        foreach ($s->kapsler() as $c) {
            [$nv] = explode(';', $c, 2);
            [$n, $v] = explode('=', $nv, 2);
            if ($v === '') {
                unset($this->kapsler[$n]);
            } else {
                $this->kapsler[$n] = $v;
            }
        }
        $j = json_decode($s->kropp, true);
        $ut = [];
        foreach (is_array($j) ? $j : [] as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return [$s, $ut];
    }

    private function kode(): string
    {
        foreach ((array) file("$this->rot/drift/installasjonskode.txt", FILE_IGNORE_NEW_LINES) as $l) {
            if (is_string($l) && $l !== '' && !str_starts_with($l, '#')) {
                return $l;
            }
        }

        return '';
    }

    public function testInstallasjonNodinnloggingOgDrift(): void
    {
        // Designpakken leveres fra appens egen server, også før installasjonen (feilsidene bruker den).
        [$s] = $this->kall('GET', '/bivrost/ressurser/stil/bivrost.css');
        self::assertSame(200, $s->status);
        self::assertSame('text/css; charset=utf-8', $s->hode('Content-Type'));
        self::assertStringContainsString('--bivrost-farge-tekst-standard', $s->kropp);
        [$s] = $this->kall('GET', '/bivrost/ressurser/stil/tema/mose.css');
        self::assertSame(200, $s->status, 'Temaets stilfil (BIV-B-028).');
        self::assertStringContainsString('temaet «mose»', $s->kropp);
        self::assertStringContainsString('--bivrost-farge-merkevare-bakgrunn', $s->kropp);
        [$s] = $this->kall('GET', '/bivrost/ressurser/fonter/gerd-sans-regular.woff2');
        self::assertSame('font/woff2', $s->hode('Content-Type'));
        [$s] = $this->kall('GET', '/bivrost/ressurser/../kjerne/.env');
        self::assertSame(404, $s->status);
        [$s] = $this->kall('GET', '/bivrost/ressurser/tokens/tema/marine.lys.normal.tokens.json');
        self::assertSame(200, $s->status, 'Tokenene kan hentes …');
        [$s] = $this->kall('GET', '/bivrost/ressurser/package.json');
        self::assertSame(404, $s->status, '… men ikke andre filer i pakken.');

        // Før installasjonen: bare «ikke installert», ingen opplysninger uten koden.
        [$s, $j] = $this->kall('GET', '/');
        self::assertSame(503, $s->status);
        [$s, $j] = $this->kall('GET', '/bivrost/api/installasjon');
        self::assertSame(['installert' => false, 'kode_kreves' => true], $j);
        self::assertSame(0600, fileperms("$this->rot/drift/installasjonskode.txt") & 0777);
        [$s] = $this->kall('GET', '/bivrost/api/installasjon', null, ['x-bivrost-installasjonskode' => 'FEIL-KODE']);
        self::assertSame(401, $s->status);
        $kode = ['x-bivrost-installasjonskode' => strtolower($this->kode())];
        [$s, $j] = $this->kall('GET', '/bivrost/api/installasjon', null, $kode);
        self::assertSame('database', $j['steg']);
        self::assertIsArray($j['forutsetninger']);

        // Kollisjon: en tabell med installasjonens prefiks stopper med en liste, og ingenting slettes.
        $this->db->pdo()->exec('CREATE TABLE bivrost_fremmed (id INT)');
        $this->db->pdo()->exec('CREATE TABLE annen_app_tabell (id INT)');
        $o = TestDb::oppsett();
        $dbFelt = ['vert' => 'localhost', 'port' => 3306, 'navn' => $this->dbNavn, 'bruker' => $o['bruker'], 'passord' => $o['passord'], 'adresse' => 'http://localhost'];
        if (isset($o['vert'])) {
            $dbFelt['vert'] = $o['vert'];
            $dbFelt['port'] = $o['port'] ?? 3306;
        }
        if (isset($o['socket'])) {
            // Lokalt via socket: .env får socketen etterpå (installasjonsskjemaet bruker vert og port).
            $dbFelt['vert'] = 'localhost';
        }
        [$s, $j] = $this->kall('POST', '/bivrost/api/installasjon/database', $dbFelt, $kode);
        if (isset($o['socket'])) {
            // PDO med «localhost» bruker standardsocketen, som her er MariaDB-socketen.
            self::assertContains($s->status, [409, 422], $s->kropp);
        }
        if ($s->status === 409) {
            self::assertSame('tabeller_finnes', $j['kode']);
            self::assertStringContainsString('bivrost_fremmed', Felt::tekst($j['detail']));
            self::assertSame('urn:bivrost:feil:tabeller_finnes', $j['type']);
            self::assertSame([], Skjema::feil('problem', $j));
            self::assertSame('application/problem+json; charset=utf-8', $s->hode('Content-Type'));
        }
        $this->db->pdo()->exec('DROP TABLE bivrost_fremmed');
        [$s, $j] = $this->kall('POST', '/bivrost/api/installasjon/database', $dbFelt, $kode);
        if ($s->status !== 200) {
            // Uten TCP-tilgang til databasen (bare socket): skriv DB-delen av .env som en driftsperson ville gjort.
            Env::skrivFil("$this->rot/.env", TestDb::env($this->dbNavn) + ['GITHUB_API_URL' => 'http://127.0.0.1:9', 'BIVROST_TILLAT_HTTP_LOKALT' => '1', 'BIVROST_ADRESSE' => 'http://localhost',
                'BIVROST_KRYPTERINGSNOKKEL' => \Bivrost\Kjerne\Krypto\Hemmelighetsboks::nyNokkel(), 'BIVROST_HMAC_NOKKEL' => 'hmac'], '', true);
        } else {
            $env = Env::fraFil("$this->rot/.env");
            self::assertSame(0640, fileperms("$this->rot/.env") & 0777);
            self::assertNotNull($env->hent('BIVROST_KRYPTERINGSNOKKEL'));
            self::assertSame('http://127.0.0.1:9', $env->hent('GITHUB_API_URL'), 'Verdier som fantes, beholdes.');
        }
        [$s, $j] = $this->kall('GET', '/bivrost/api/installasjon', null, $kode);
        self::assertSame('bruker', $j['steg']);

        // Den første driftsbrukeren: passordet sjekkes, TOTP må bekreftes.
        [$s, $j] = $this->kall('POST', '/bivrost/api/installasjon/bruker', ['brukernavn' => 'drift', 'navn' => 'Drift Driftesen', 'passord' => 'passord12345'], $kode);
        self::assertSame(422, $s->status);
        self::assertSame('passord_svakt', $j['kode']);
        [$s, $j] = $this->kall('POST', '/bivrost/api/installasjon/bruker', ['brukernavn' => 'Drift', 'navn' => 'Drift Driftesen', 'tittel' => 'Driftsansvarlig', 'passord' => 'lang og god passfrase'], $kode);
        self::assertSame(200, $s->status, $s->kropp);
        self::assertStringStartsWith('otpauth://totp/', Felt::tekst($j['otpauth']));
        $hemmelighet = Felt::tekst($j['hemmelighet']);
        $totp = new Totp($this->klokke);
        [$s, $j] = $this->kall('POST', '/bivrost/api/installasjon/fullfor', ['kode' => '000000'], $kode);
        self::assertSame(422, $s->status);
        [$s, $j] = $this->kall('POST', '/bivrost/api/installasjon/fullfor', ['kode' => $totp->kode($hemmelighet)], $kode);
        self::assertSame(200, $s->status, $s->kropp);
        self::assertCount(10, (array) $j['gjenopprettingskoder']);
        $koder = array_map(Felt::tekst(...), array_values((array) $j['gjenopprettingskoder']));
        self::assertTrue($this->db->harTabell('app_ting'));
        self::assertTrue($this->db->harTabell('annen_app_tabell'), 'Andre tabeller er urørt.');
        self::assertFileExists("$this->rot/drift/installert.json");
        self::assertFileDoesNotExist("$this->rot/drift/installasjonskode.txt");

        // Utgivernøkkelen som fulgte med Kjerne, er klarert ved installasjonen (tillit ved første bruk, BIV-DRF-14).
        $klarert = (new Utgivernokler($this->db))->hent('bivrost-kjerne');
        self::assertIsArray($klarert);
        self::assertSame($this->utgiver, $klarert['nokkel']);
        self::assertSame('installasjon', $klarert['kilde']);
        self::assertNull((new Utgivernokler($this->db))->hent('testapp'), 'Appen har ingen nøkkelfil.');
        $h = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:nokkel'));
        self::assertCount(1, $h);
        self::assertSame(Utgivernokler::fingeravtrykk($this->utgiver), $h[0]['hendelse']->etter['fingeravtrykk']['tekst'] ?? null);

        // Etterpå: installasjonen svarer 404.
        [$s] = $this->kall('GET', '/bivrost/api/installasjon', null, $kode);
        self::assertSame(404, $s->status);

        // Ingen innlogging over HTTP: flagget for lokal utvikling virker bare når alt er loopback.
        [$s] = $this->kall('GET', '/bivrost/api/okt', null, [], 'gerd.example.no');
        self::assertSame(403, $s->status);
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => 'lang og god passfrase'], [], 'localhost', true, '10.0.0.5');
        self::assertSame(403, $s->status, 'Flagget er satt, men klienten er ikke loopback.');
        self::assertSame('krever_https', $j['kode']);

        // Nødinnlogging: CSRF-nøkkel fra økten, passord, så TOTP.
        [$s, $j] = $this->kall('GET', '/bivrost/api/okt');
        self::assertNull($j['innlogget']);
        $this->csrf = Felt::tekst($j['csrf']);
        [$s] = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => 'lang og god passfrase'], [], 'localhost', false);
        self::assertSame(403, $s->status, 'Uten CSRF-nøkkel avvises forespørselen.');
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => 'lang og god passfrase'], ['origin' => 'https://angriper.test']);
        self::assertSame(403, $s->status, 'Et annet opphav avvises.');
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => 'lang og god passfrase']);
        self::assertSame('totp', $j['steg'] ?? null, $s->kropp);
        $this->csrf = Felt::tekst($j['csrf']);
        [$s] = $this->kall('GET', '/bivrost/api/system/oppdatering');
        self::assertSame(401, $s->status, 'Passordet alene gir ingen tilgang.');
        $this->klokke->frem(30);
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal/totp', ['kode' => $totp->kode($hemmelighet)]);
        self::assertSame(200, $s->status, $s->kropp);
        self::assertIsArray($j['innlogget']);
        self::assertSame('Drift Driftesen', $j['innlogget']['navn']);
        self::assertSame([['urn' => 'urn:bivrost:tilgang:drift', 'navn' => 'Drift']], $j['innlogget']['tilganger']);
        $this->csrf = Felt::tekst($j['csrf']);

        // Drift: oppdateringsstatus (GitHub svarer ikke her), sikkerhetskopi, nedlasting og eksport.
        [$s, $j] = $this->kall('GET', '/bivrost/api/system/oppdatering');
        self::assertSame(200, $s->status, $s->kropp);
        self::assertIsString($j['plan_feil']);
        $kjerne = is_array($j['pakker'] ?? null) ? $j['pakker'][0] : null;
        self::assertIsArray($kjerne);
        self::assertTrue($kjerne['signatur_kreves']);
        self::assertIsArray($kjerne['utgivernokkel']);
        self::assertSame('klarert', $kjerne['utgivernokkel']['kilde']);
        self::assertSame(Utgivernokler::fingeravtrykk($this->utgiver), $kjerne['utgivernokkel']['fingeravtrykk']);
        [$s, $j] = $this->kall('POST', '/bivrost/api/system/sikkerhetskopier', []);
        self::assertSame(200, $s->status, $s->kropp);
        [$s] = $this->kall('GET', '/bivrost/api/system/sikkerhetskopier/' . Felt::tekst($j['db']));
        self::assertSame(200, $s->status);
        self::assertNotNull($s->filsti());
        [$s] = $this->kall('GET', '/bivrost/api/system/sikkerhetskopier/..%2F.env');
        self::assertSame(404, $s->status);
        [$s] = $this->kall('GET', '/bivrost/api/system/eksport');
        self::assertSame(200, $s->status);
        $raa = (string) file_get_contents((string) $s->filsti());
        $eksport = json_decode($raa, true);
        self::assertIsArray($eksport);
        self::assertSame('bivrost-eksport', $eksport['format']);
        self::assertIsArray($eksport['laget_av']);
        self::assertSame('Drift Driftesen', $eksport['laget_av']['navn']);
        self::assertNotEmpty($eksport['tilgangshistorikk']);
        self::assertStringNotContainsString('passord_hash', (string) json_encode($eksport));
        self::assertSame([], Skjema::feilJson('eksport', $raa), 'Eksporten følger skjema/eksport.schema.json.');
        unlink((string) $s->filsti());
        [$s] = $this->kall('GET', '/bivrost/api/system/logg.jsonl?format=cloudevents');
        self::assertSame(200, $s->status);
        $linjer = Felt::ellers(file((string) $s->filsti(), FILE_IGNORE_NEW_LINES), []);
        self::assertNotEmpty($linjer);
        foreach ($linjer as $l) {
            $ce = json_decode($l, true);
            self::assertIsArray($ce);
            self::assertSame('1.0', $ce['specversion']);
        }
        unlink((string) $s->filsti());
        [$s, $j] = $this->kall('GET', '/bivrost/api/system/logg?type=urn:bivrost:hendelse:innlogging');
        self::assertSame(200, $s->status);
        self::assertIsArray($j['hendelser']);
        self::assertIsArray($j['hendelser'][0]);
        self::assertIsArray($j['hendelser'][0]['hva']);
        self::assertSame('Innlogging', $j['hendelser'][0]['hva']['tekst']);

        // Preferansene for en lokal bruker lagres lokalt (ingen Sentral).
        [$s, $j] = $this->kall('PUT', '/bivrost/api/meg/preferanser', ['felles' => ['tema' => 'vinrod'], 'app' => ['bredde' => 'full']]);
        self::assertSame(200, $s->status, $s->kropp);
        self::assertIsArray($j['effektive']);
        self::assertSame('vinrod', $j['effektive']['tema']);
        self::assertSame([], Skjema::feil('preferanser', $j['effektive']));
        self::assertSame('full', $j['effektive']['bredde']);
        [$s, $j] = $this->kall('PUT', '/bivrost/api/meg/preferanser', ['felles' => ['tema' => ['liste']]]);
        self::assertSame(422, $s->status);
        self::assertSame([], Skjema::feil('problem', $j));
        [$s, $j] = $this->kall('GET', '/bivrost/api/system/rolleregister');
        self::assertSame([], Skjema::feilJson('rolleregister', $s->kropp));

        // Utlogging.
        [$s] = $this->kall('POST', '/bivrost/api/utlogging', []);
        self::assertSame(200, $s->status);
        [$s, $j] = $this->kall('GET', '/bivrost/api/okt');
        self::assertNull($j['innlogget']);

        // BIVROST_KRYPTERINGSNOKKEL byttes: TOTP kan ikke leses, men svaret er ikke 500, og en gjenopprettingskode
        // virker. Deretter setter driftsbrukeren ny TOTP (overdragbarhet.md punkt 12).
        $this->csrf = Felt::tekst($j['csrf']);
        $env = Env::fraFil("$this->rot/.env")->alle();
        Env::skrivFil("$this->rot/.env", ['BIVROST_KRYPTERINGSNOKKEL' => Hemmelighetsboks::nyNokkel()] + $env, overskriv: true);
        $this->klokke->frem(30);
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => 'lang og god passfrase']);
        self::assertSame(200, $s->status, $s->kropp);
        $this->csrf = Felt::tekst($j['csrf']);
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal/totp', ['kode' => $totp->kode($hemmelighet)]);
        self::assertSame(401, $s->status, $s->kropp);
        self::assertSame('totp_ulesbar', $j['kode'] ?? null);
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal/totp', ['kode' => $koder[0]]);
        self::assertSame(200, $s->status, $s->kropp);
        self::assertFalse($j['totp_lesbar']);
        $this->csrf = Felt::tekst($j['csrf']);
        [$s, $j] = $this->kall('POST', '/bivrost/api/meg/totp', ['passord' => 'feil passord her']);
        self::assertSame(403, $s->status, $s->kropp);
        [$s, $j] = $this->kall('POST', '/bivrost/api/meg/totp', ['passord' => 'lang og god passfrase']);
        self::assertSame(200, $s->status, $s->kropp);
        $ny = Felt::tekst($j['hemmelighet']);
        self::assertStringStartsWith('otpauth://totp/', Felt::tekst($j['otpauth']));
        [$s, $j] = $this->kall('POST', '/bivrost/api/meg/totp/bekreft', ['kode' => $totp->kode($ny)]);
        self::assertSame(200, $s->status, $s->kropp);
        self::assertCount(10, (array) $j['gjenopprettingskoder']);
        $this->csrf = Felt::tekst($j['csrf']);
        [$s] = $this->kall('POST', '/bivrost/api/utlogging', []);
        self::assertSame(200, $s->status);
        [$s, $j] = $this->kall('GET', '/bivrost/api/okt');
        $this->csrf = Felt::tekst($j['csrf']);
        $this->klokke->frem(30);
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => 'lang og god passfrase']);
        $this->csrf = Felt::tekst($j['csrf']);
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal/totp', ['kode' => $koder[1]]);
        self::assertSame(401, $s->status, 'De gamle gjenopprettingskodene virker ikke etter en ny TOTP.');
        [$s, $j] = $this->kall('POST', '/bivrost/api/innlogging/lokal/totp', ['kode' => $totp->kode($ny)]);
        self::assertSame(200, $s->status, $s->kropp);
        self::assertTrue($j['totp_lesbar']);
        $byttet = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:innlogging:totp_byttet'));
        self::assertCount(1, $byttet);
        self::assertStringNotContainsString($ny, (string) json_encode($byttet[0]['hendelse']->tilListe()), 'Hemmeligheten står aldri i loggen.');

        $logg = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:installasjon'));
        self::assertSame('System (installasjon)', $logg[0]['hendelse']->hvem['navn']);
    }

    public function testUtenFlaggetNektesHttpOgsaLokalt(): void
    {
        Env::skrivFil("$this->rot/.env", ['GITHUB_API_URL' => 'http://127.0.0.1:9'], '', true);
        [$s, $j] = $this->kall('GET', '/bivrost/api/installasjon');
        self::assertSame(403, $s->status);
        self::assertSame('krever_https', $j['kode']);
        Env::skrivFil("$this->rot/.env", ['BIVROST_ADRESSE' => 'https://gerd.example.no'], '', true);
        [$s] = $this->kall('GET', '/bivrost/api/installasjon');
        self::assertSame(308, $s->status, 'GET over HTTP sendes videre til HTTPS-adressen fra .env (aldri fra Host).');
        self::assertSame('https://gerd.example.no/bivrost/api/installasjon', $s->hode('Location'));
        [$s] = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'x', 'passord' => 'y']);
        self::assertSame(403, $s->status, 'Hemmeligheter tas aldri imot over HTTP.');
    }

    public function testInstallasjonskodenHarGrensePerIp(): void
    {
        $this->kall('GET', '/bivrost/api/installasjon');
        $siste = 0;
        for ($i = 0; $i < 11; $i++) {
            [$s] = $this->kall('GET', '/bivrost/api/installasjon', null, ['x-bivrost-installasjonskode' => "FEIL-$i"]);
            $siste = $s->status;
        }
        self::assertSame(429, $siste);
        [$s] = $this->kall('GET', '/bivrost/api/installasjon', null, ['x-bivrost-installasjonskode' => $this->kode()]);
        self::assertSame(429, $s->status, 'Også riktig kode venter når grensen er nådd.');
    }

    public function testMislykketFullforingSletterBareTabelleneDenLaget(): void
    {
        file_put_contents("$this->rot/app/migrasjoner/002_feil.sql", 'CREATE TABLE app_mer (id INT); SELECT * FROM finnes_ikke;');
        $this->db->pdo()->exec('CREATE TABLE annen_app_tabell (id INT)');
        Env::skrivFil("$this->rot/.env", TestDb::env($this->dbNavn) + ['GITHUB_API_URL' => 'http://127.0.0.1:9', 'BIVROST_TILLAT_HTTP_LOKALT' => '1', 'BIVROST_ADRESSE' => 'http://localhost',
            'BIVROST_KRYPTERINGSNOKKEL' => \Bivrost\Kjerne\Krypto\Hemmelighetsboks::nyNokkel(), 'BIVROST_HMAC_NOKKEL' => 'hmac'], '', true);
        $this->kall('GET', '/bivrost/api/installasjon');
        $kode = ['x-bivrost-installasjonskode' => $this->kode()];
        [$s, $j] = $this->kall('POST', '/bivrost/api/installasjon/bruker', ['brukernavn' => 'drift', 'navn' => 'Drift', 'passord' => 'lang og god passfrase'], $kode);
        self::assertSame(200, $s->status, $s->kropp);
        [$s] = $this->kall('POST', '/bivrost/api/installasjon/fullfor', ['kode' => (new Totp($this->klokke))->kode(Felt::tekst($j['hemmelighet']))], $kode);
        self::assertSame(500, $s->status);
        self::assertStringNotContainsString('finnes_ikke', $s->kropp, 'Feilsvaret har ingen interne detaljer.');
        $tabeller = array_map(static fn (array $r): string => Felt::tekst(array_values($r)[0]), $this->db->alle('SHOW TABLES'));
        self::assertSame(['annen_app_tabell'], $tabeller, 'Bare tabellene installasjonen laget, er slettet.');
        self::assertFileDoesNotExist("$this->rot/drift/installert.json");
        [$s, $j] = $this->kall('GET', '/bivrost/api/installasjon');
        self::assertSame(['installert' => false, 'kode_kreves' => true], $j);
    }
}
