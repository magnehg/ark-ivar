<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\EndeTilEnde;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Kjerne;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Logg\DbLoggLager;
use Bivrost\Kjerne\Logg\Hendelse;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Oppdatering\Pakkeleser;
use Bivrost\Kjerne\Oppdatering\Signatur;
use Bivrost\Kjerne\Oppdatering\Tilbakerulling;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\PakkeBygger;
use Bivrost\Kjerne\Tester\Stotte\Skjema;
use Bivrost\Kjerne\Tester\Stotte\Tjener;

/**
 * Hele oppdateringen ende til ende mot et etterlignet GitHub (PHPs webserver): flere pakker, kompatibilitet,
 * signatur, sjekksum, ett steg per kall, migreringer av den nye koden, tilbakerulling og oppdaterings-API-et.
 */
final class OppdateringTest extends DbTestCase
{
    private string $m = '';
    private string $rot = '';
    private string $pakker = '';
    private string $logg = '';
    private ?Tjener $github = null;

    /** @var array<string, string> */
    private array $env = [];

    /** @var array{offentlig: string, hemmelig: string} */
    private array $nokkel;

    /** @var array{offentlig: string, hemmelig: string} */
    private array $appNokkel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = Mappe::ny();
        $this->rot = "$this->m/rot";
        $this->pakker = "$this->m/pakker";
        $this->logg = "$this->m/github.jsonl";
        mkdir($this->rot);
        mkdir("$this->pakker/test/bivrost", 0755, true);
        mkdir("$this->pakker/test/testapp", 0755, true);
        $this->nokkel = Signatur::nyttNokkelpar();
        $this->appNokkel = Signatur::nyttNokkelpar();
        $kb = "$this->pakker/test/bivrost";
        $ab = "$this->pakker/test/testapp";
        $this->lagKjerne('0.1.0', ['src/A.php' => '<?php // 0.1', 'src/Gammel.php' => '<?php // fjernes i 0.2']);
        $this->lagKjerne('0.2.0', ['src/A.php' => '<?php // 0.2', 'src/Ny.php' => '<?php // ny i 0.2', 'vendor/lib/x.php' => '<?php']);
        $this->lagKjerne('0.3.0', ['src/A.php' => '<?php // 0.3'], ['php' => '>=99.0']);
        $mig1 = 'CREATE TABLE IF NOT EXISTS testapp_a (id INT PRIMARY KEY);';
        $this->lagApp('1.0.0', ['app.php' => '<?php // 1.0', 'gammel.php' => '<?php', 'migrasjoner/001_a.sql' => $mig1], ['bivrost-kjerne' => '^0.1']);
        $this->lagApp('1.1.0', ['app.php' => '<?php // 1.1', 'migrasjoner/001_a.sql' => $mig1, 'migrasjoner/002_ny.sql' => 'CREATE TABLE IF NOT EXISTS testapp_ny (id INT);'], ['bivrost-kjerne' => '^0.2']);
        $this->lagApp('2.0.0', ['app.php' => '<?php // 2.0', 'migrasjoner/001_a.sql' => $mig1], ['bivrost-kjerne' => '^0.3']);
        file_put_contents("$ab/utkast.txt", '');
        // Installert: Kjerne 0.1.0 og testapp 1.0.0 (pakket ut som oppdateringen ville gjort).
        foreach (["$kb/bivrost-kjerne-v0.1.0.zip", "$ab/testapp-v1.0.0.zip"] as $zip) {
            $z = new \ZipArchive();
            $z->open($zip);
            $z->extractTo($this->rot);
            $z->close();
        }
        $this->github = new Tjener(__DIR__ . '/../Stotte/github-ruter.php', ['MOCK_PAKKER' => $this->pakker, 'MOCK_LOGG' => $this->logg]);
        $env = \Bivrost\Kjerne\Tester\Stotte\TestDb::env($this->dbNavn) + [
            'BIVROST_KRYPTERINGSNOKKEL' => Hemmelighetsboks::nyNokkel(),
            'BIVROST_HMAC_NOKKEL' => 'hmac-test',
            'BIVROST_ADRESSE' => 'http://localhost',
            'BIVROST_TILLAT_HTTP_LOKALT' => '1',
            'GITHUB_API_URL' => 'http://127.0.0.1:' . $this->github->port,
            'BIVROST_GITHUB_TOKEN' => 'ghs_testtoken',
            'BIVROST_REPO' => 'test/bivrost',
            'BIVROST_OFFENTLIG_NOKKEL' => $this->nokkel['offentlig'],
            'TESTAPP_OFFENTLIG_NOKKEL' => $this->appNokkel['offentlig'],
        ];
        Env::skrivFil("$this->rot/.env", $env);
        $this->env = $env;
        $k = $this->kjerne();
        $k->migrerAlle();
        // Appens faglogg (BIV-LOG-04), som appen ville laget i en migrering.
        $this->db->utfor(DbLoggLager::tabellSql('testapp_logg'));
        file_put_contents("$this->rot/drift/installert.json", '{}');
    }

    /**
     * @param array<string, string> $filer
     * @param array<string, string> $krever
     */
    private function lagKjerne(string $v, array $filer, array $krever = []): void
    {
        PakkeBygger::lag("$this->pakker/test/bivrost", 'bivrost-kjerne', 'bivrost-kjerne-v', 'bivrost/kjerne/', $v, $filer, $krever + ['php' => '>=8.2'], $this->nokkel['hemmelig']);
    }

    /**
     * @param array<string, string> $filer
     * @param array<string, string> $krever
     */
    private function lagApp(string $v, array $filer, array $krever, ?string $oppdateresFra = null): void
    {
        PakkeBygger::lag("$this->pakker/test/testapp", 'testapp', 'testapp-v', 'testapp/', $v, $filer, $krever, $this->appNokkel['hemmelig'], [], $oppdateresFra);
    }

    /**
     * @param array<string, string> $filer
     * @param array<string, string> $krever
     */
    private function lagKjerneMedStopp(string $v, array $filer, array $krever, string $oppdateresFra): void
    {
        PakkeBygger::lag("$this->pakker/test/bivrost", 'bivrost-kjerne', 'bivrost-kjerne-v', 'bivrost/kjerne/', $v, $filer, $krever, $this->nokkel['hemmelig'], [], $oppdateresFra);
    }

    /**
     * Kjører steg til resultatet er «ingenting», som driften eller maskin-API-et gjør.
     *
     * @return list<string>
     */
    private function kjorTilFerdig(int $maks = 20): array
    {
        $steg = [];
        for ($i = 0; $i < $maks; $i++) {
            $r = $this->kjerne()->oppdaterer()->kjor();
            $steg[] = $r['resultat'];
            if ($r['resultat'] === 'ingenting') {
                break;
            }
        }

        return $steg;
    }

    protected function tearDown(): void
    {
        $this->github?->stopp();
        Mappe::slett($this->m);
        parent::tearDown();
    }

    private function kjerne(): Kjerne
    {
        if (!is_dir("$this->rot/drift")) {
            mkdir("$this->rot/drift", 0750);
        }

        return new Kjerne(AppKonfig::fraListe([
            'app' => 'testapp', 'appnavn' => 'Testappen', 'rot' => $this->rot,
            'migreringer' => "$this->rot/testapp/migrasjoner", 'tabellprefikser' => ['testapp_'],
            'pakke' => ['repo' => 'test/testapp', 'tagprefiks' => 'testapp-v', 'mappe' => 'testapp/'],
            'logg' => ['tabeller' => ['testapp_logg', 'testapp_faglogg']],
        ]), new FastKlokke());
    }

    /** @return array<string, mixed> */
    private function versjon(string $mappe): array
    {
        return Pakkeleser::json("$this->rot/$mappe/versjon.json");
    }

    public function testFlerePakkerEttStegPerKallMedKompatibilitetOgTilbakerulling(): void
    {
        $k = $this->kjerne();
        $plan = array_map(static fn (array $x): string => $x['pakke'] . ' ' . $x['fra'] . ' → ' . $x['til'], $k->oppdaterer()->plan());
        self::assertSame(['bivrost-kjerne 0.1.0 → 0.2.0', 'testapp 1.0.0 → 1.1.0'], $plan, 'Nyeste kombinasjon som virker sammen (0.3.0 krever PHP 99, 2.0.0 krever 0.3).');
        $steg = [];
        for ($i = 0; $i < 6; $i++) {
            // Hvert kall er en ny forespørsel (ny Kjerne), som på et webhotell.
            $r = $this->kjerne()->oppdaterer()->kjor();
            $steg[] = $r['resultat'];
            if ($r['resultat'] === 'ingenting') {
                break;
            }
        }
        self::assertSame(['hentet', 'hentet', 'filer', 'database', 'ingenting'], $steg);
        self::assertSame('0.2.0', $this->versjon('bivrost/kjerne')['versjon']);
        self::assertSame('1.1.0', $this->versjon('testapp')['versjon']);
        self::assertFileDoesNotExist("$this->rot/bivrost/kjerne/src/Gammel.php", 'Filer som ikke lenger er med, fjernes.');
        self::assertFileDoesNotExist("$this->rot/testapp/gammel.php");
        self::assertSame('<?php // ny i 0.2', file_get_contents("$this->rot/bivrost/kjerne/src/Ny.php"));
        self::assertTrue($this->db->harTabell('testapp_ny'), 'Den nye migreringen er kjørt.');
        self::assertFileExists("$this->rot/drift/.htaccess");

        // Én korrelasjons-ID for hele oppdateringen, og navnene i klartekst.
        $hendelser = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering'));
        $korr = array_unique(array_map(static fn (array $h): string => $h['hendelse']->korrelasjon, $hendelser));
        self::assertCount(1, $korr);
        self::assertNotSame([], $hendelser);
        self::assertSame('Versjonspakke hentet og kontrollert', $hendelser[count($hendelser) - 1]['hendelse']->hva['tekst']);

        // Tokenet til GitHub sendes ikke til nedlastingsadressen (ny vert ville ikke fått det heller).
        $linjer = array_map(static fn (string $l): mixed => json_decode($l, true), Felt::ellers(file($this->logg, FILE_IGNORE_NEW_LINES), []));
        foreach ($linjer as $l) {
            if (is_array($l) && str_starts_with(Felt::tekst($l['sti']), '/repos/')) {
                self::assertSame('Bearer ghs_testtoken', $l['auth']);
            }
        }

        // Appens faglogg etter punktet: testapp_logg fantes ved punktet, testapp_faglogg lages etterpå (som av en
        // senere migrering). Begge skal overleve tilbakerullingen (BIV-B-008).
        $this->db->utfor(DbLoggLager::tabellSql('testapp_faglogg'));
        $fag = [];
        foreach (['testapp_logg', 'testapp_faglogg'] as $tabell) {
            $h = Hendelse::fraListe(['id' => Tilfeldig::uuid()] + $hendelser[0]['hendelse']->tilListe());
            (new DbLoggLager($this->db, $tabell))->skriv($h);
            $fag[$tabell] = $h->id;
        }

        // Tilbakerulling til punktet fra oppdateringen: filer og database sammen, loggene beholdes.
        $t = new Tilbakerulling("$this->rot/drift", $this->db, $this->kjerne()->logg(), ...[...$this->kopier(), ['testapp_logg', 'testapp_faglogg']]);
        $punkter = $t->punkter();
        self::assertCount(1, $punkter);
        self::assertSame([], Skjema::feil('gjenopprettingspunkt', json_decode((string) file_get_contents("$this->rot/drift/sikkerhetskopier/{$punkter[0]['navn']}"), true)));
        self::assertSame([], Skjema::feil('versjon', $this->versjon('testapp')));
        $antallLogg = count($this->kjerne()->logg()->lager()->les(new LoggFilter()));
        $r = $t->tilbakerull($punkter[0]['navn']);
        self::assertSame([], $r['avvik']);
        self::assertSame('0.1.0', $this->versjon('bivrost/kjerne')['versjon']);
        self::assertSame('1.0.0', $this->versjon('testapp')['versjon']);
        self::assertFileExists("$this->rot/bivrost/kjerne/src/Gammel.php");
        self::assertFileDoesNotExist("$this->rot/bivrost/kjerne/src/Ny.php");
        self::assertFalse($this->db->harTabell('testapp_ny'));
        self::assertGreaterThan($antallLogg, count($this->kjerne()->logg()->lager()->les(new LoggFilter())), 'Revisjonsloggen etter punktet er lagt inn igjen.');
        foreach ($fag as $tabell => $id) {
            self::assertSame(1, Felt::tall($this->db->verdi("SELECT COUNT(*) FROM `$tabell` WHERE id = ?", [$id])), "Appens faglogg i $tabell er lagt inn igjen.");
            self::assertSame(1, $r['logg_per_tabell'][$tabell]);
        }
        self::assertGreaterThan(0, $r['logg_per_tabell']['bivrost_logg']);

        // Etter tilbakerullingen holdes automatisk oppdatering igjen.
        try {
            $this->kjerne()->oppdaterer()->kjor(true);
            self::fail('API-et skal ikke oppdatere etter en tilbakerulling.');
        } catch (HttpFeil $e) {
            self::assertSame('oppdatering_holdt_igjen', $e->kode);
        }
    }

    /** @return array{0: \Bivrost\Kjerne\Sikkerhetskopi\Databasekopi, 1: \Bivrost\Kjerne\Sikkerhetskopi\Filkopi, 2: \Bivrost\Kjerne\Sikkerhetskopi\Kopilager, 3: \Bivrost\Kjerne\Oppdatering\Oppdaterer, 4: \Bivrost\Kjerne\Oppdatering\Utgivernokler} */
    private function kopier(): array
    {
        $k = $this->kjerne();
        $lager = $k->kopilager();

        return [
            new \Bivrost\Kjerne\Sikkerhetskopi\Databasekopi($this->db, ['bivrost_', 'testapp_'], $lager),
            new \Bivrost\Kjerne\Sikkerhetskopi\Filkopi($this->rot, $lager),
            $lager,
            $k->oppdaterer(),
            new \Bivrost\Kjerne\Oppdatering\Utgivernokler($this->db),
        ];
    }

    public function testFeilSignaturStopperForNoeErEndret(): void
    {
        $ab = "$this->pakker/test/testapp";
        $annen = Signatur::nyttNokkelpar();
        $sha = (string) hash_file('sha256', "$ab/testapp-v1.1.0.zip");
        file_put_contents("$ab/testapp-v1.1.0.zip.sig", Signatur::signer($annen['hemmelig'], 'testapp', 'testapp-v1.1.0', $sha));
        self::assertSame('hentet', $this->kjerne()->oppdaterer()->kjor()['resultat']);
        try {
            $this->kjerne()->oppdaterer()->kjor();
            self::fail('Feil signatur.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.signatur', $e->tekstnokkel);
        }
        self::assertSame('0.1.0', $this->versjon('bivrost/kjerne')['versjon']);
        self::assertNull($this->kjerne()->oppdaterer()->status(false)['pagaende'], 'Tilstanden er ryddet.');
        $feil = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:feil'));
        self::assertSame('bivrost.feil.oppdatering.signatur', $feil[0]['hendelse']->data['feil'] ?? null);
    }

    public function testFeilSjekksumOgUsignertPakke(): void
    {
        $kb = "$this->pakker/test/bivrost";
        file_put_contents("$kb/bivrost-kjerne-v0.2.0.zip.sha256", str_repeat('0', 64) . "  x.zip\n");
        try {
            $this->kjerne()->oppdaterer()->kjor();
            self::fail('Feil sjekksum.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.sjekksum', $e->tekstnokkel);
        }
        file_put_contents("$kb/bivrost-kjerne-v0.2.0.zip.sha256", hash_file('sha256', "$kb/bivrost-kjerne-v0.2.0.zip") . "  x.zip\n");
        unlink("$kb/bivrost-kjerne-v0.2.0.zip.sig");
        try {
            $this->kjerne()->oppdaterer()->kjor();
            self::fail('Usignert pakke når signatur kreves.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.usignert', $e->tekstnokkel);
        }
        self::assertSame('0.1.0', $this->versjon('bivrost/kjerne')['versjon']);
    }

    /**
     * Mellomstopp (BIV-DRF-16): Kjerne 0.4.0 kan bare oppdateres fra 0.2.0 eller nyere, og appen 2.1.0 bare fra 1.1.0.
     * Fra 0.1.0 og 1.0.0 går installasjonen først til 0.2.0 og 1.1.0, og så videre til 0.4.0 og 2.1.0, i én
     * sammenhengende kjøring av stegene. En versjon som ingen installert versjon kan nå, gir uten_vei.
     */
    public function testMellomstoppLeggesInnForstOgOppdateringenFortsetterAutomatisk(): void
    {
        $this->lagKjerneMedStopp('0.4.0', ['src/A.php' => '<?php // 0.4'], ['php' => '>=8.2'], '>=0.2.0');
        $mig1 = 'CREATE TABLE IF NOT EXISTS testapp_a (id INT PRIMARY KEY);';
        $this->lagApp('2.1.0', ['app.php' => '<?php // 2.1', 'migrasjoner/001_a.sql' => $mig1], ['bivrost-kjerne' => '^0.4'], '>=1.1.0');

        $plan = array_map(
            static fn (array $x): string => $x['pakke'] . ' ' . $x['til'] . ($x['mellomstopp'] ? ' (stopp)' : ''),
            $this->kjerne()->oppdaterer()->plan()
        );
        self::assertSame(['bivrost-kjerne 0.2.0 (stopp)', 'testapp 1.1.0 (stopp)'], $plan, 'Først mellomstoppene.');
        self::assertSame([], $this->kjerne()->oppdaterer()->status(true)['uten_vei']);

        $steg = $this->kjorTilFerdig();
        self::assertSame(['hentet', 'hentet', 'filer', 'database', 'hentet', 'hentet', 'filer', 'database', 'ingenting'], $steg, 'Én kjøring tar begge stoppene.');
        self::assertSame('0.4.0', $this->versjon('bivrost/kjerne')['versjon']);
        self::assertSame('2.1.0', $this->versjon('testapp')['versjon']);
        self::assertSame('>=0.2.0', $this->versjon('bivrost/kjerne')['oppdateres_fra']);
        self::assertSame([], Skjema::feil('versjon', $this->versjon('bivrost/kjerne')), 'versjon.json med oppdateres_fra følger skjemaet.');

        // En versjon som krever en versjon som ikke finnes, kan ingen nå: statusen sier det.
        $this->lagKjerneMedStopp('0.6.0', ['src/A.php' => '<?php // 0.6'], ['php' => '>=8.2'], '>=0.5.0');
        $s = $this->kjerne()->oppdaterer()->status(true);
        self::assertSame([], $s['plan']);
        self::assertSame(['bivrost-kjerne'], $s['uten_vei']);
    }

    /**
     * Stoppet finnes også når det har kommet flere enn 30 versjoner etter det: planen leter nedenfra (BIV-DRF-16).
     */
    public function testMellomstoppFinnesEtterMangeNyereVersjoner(): void
    {
        // Bare appversjonene i denne testen: 1.1.0 og 2.0.0 fra oppsettet fjernes.
        foreach (Felt::ellers(glob("$this->pakker/test/testapp/testapp-v{1.1.0,2.0.0}.*", GLOB_BRACE), []) as $f) {
            unlink($f);
        }
        $mig1 = 'CREATE TABLE IF NOT EXISTS testapp_a (id INT PRIMARY KEY);';
        for ($i = 1; $i <= 32; $i++) {
            $this->lagApp("1.1.$i", ['app.php' => "<?php // 1.1.$i", 'migrasjoner/001_a.sql' => $mig1], ['bivrost-kjerne' => '^0.1'], '>=1.0.5');
        }
        $this->lagApp('1.0.5', ['app.php' => '<?php // 1.0.5', 'migrasjoner/001_a.sql' => $mig1], ['bivrost-kjerne' => '^0.1']);
        $plan = array_values(array_filter($this->kjerne()->oppdaterer()->plan(), static fn (array $x): bool => $x['pakke'] === 'testapp'));
        self::assertCount(1, $plan);
        self::assertSame('1.0.5', $plan[0]['til'], 'Stoppet 1.0.5 er funnet under 32 nyere versjoner.');
        self::assertTrue($plan[0]['mellomstopp']);
    }

    /**
     * Den nyeste appversjonen (2.0.0) kan nås, men passer ikke (den krever Kjerne 0.3, som krever en nyere PHP), og
     * versjonene under den kan bare oppdateres fra 1.0.5. Planen leter da nedenfra og finner stoppet 1.0.5.
     */
    public function testStoppFinnesNarDenNyesteKanNasMenIkkePasser(): void
    {
        foreach (Felt::ellers(glob("$this->pakker/test/testapp/testapp-v1.1.0.*"), []) as $f) {
            unlink($f);
        }
        $mig1 = 'CREATE TABLE IF NOT EXISTS testapp_a (id INT PRIMARY KEY);';
        for ($i = 1; $i <= 6; $i++) {
            $this->lagApp("1.1.$i", ['app.php' => "<?php // 1.1.$i", 'migrasjoner/001_a.sql' => $mig1], ['bivrost-kjerne' => '^0.1'], '>=1.0.5');
        }
        $this->lagApp('1.0.5', ['app.php' => '<?php // 1.0.5', 'migrasjoner/001_a.sql' => $mig1], ['bivrost-kjerne' => '^0.1']);
        $plan = array_values(array_filter($this->kjerne()->oppdaterer()->plan(), static fn (array $x): bool => $x['pakke'] === 'testapp'));
        self::assertCount(1, $plan);
        self::assertSame('1.0.5', $plan[0]['til']);
        self::assertTrue($plan[0]['mellomstopp']);
    }

    /**
     * Planen er laget i et tidligere kall og lagret i tilstand.json, for eksempel med en endret <tag>.versjon.json som
     * siden er lagt tilbake. Hentingen kontrollerer mellomstoppet mot den signerte versjon.json, ikke bare mot filen
     * hos GitHub (TOCTOU).
     */
    public function testHentingenKontrollererMellomstoppetMotDenSignertePakken(): void
    {
        $this->lagKjerneMedStopp('0.4.0', ['src/A.php' => '<?php // 0.4'], ['php' => '>=8.2'], '>=0.2.0');
        $plan = [['pakke' => 'bivrost-kjerne', 'fra' => '0.1.0', 'til' => '0.4.0', 'tag' => 'bivrost-kjerne-v0.4.0', 'mellomstopp' => false]];
        $tilstand = ['steg' => 'hent', 'korrelasjon' => '00000000-0000-4000-8000-000000000000', 'startet' => '2026-11-14T10:00:00+00:00', 'plan' => $plan, 'hentet' => []];
        @mkdir("$this->rot/drift/oppdatering", 0750, true);
        file_put_contents("$this->rot/drift/oppdatering/tilstand.json", (string) json_encode($tilstand));
        try {
            $this->kjerne()->oppdaterer()->kjor();
            self::fail('0.1.0 kan ikke oppdateres rett til 0.4.0.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.versjonsfil', $e->tekstnokkel);
        }
        self::assertSame('0.1.0', $this->versjon('bivrost/kjerne')['versjon'], 'Ingenting er endret.');

        // Det samme for «krever»: 0.3.0 krever PHP 99 i den signerte pakken.
        $plan = [['pakke' => 'bivrost-kjerne', 'fra' => '0.1.0', 'til' => '0.3.0', 'tag' => 'bivrost-kjerne-v0.3.0', 'mellomstopp' => false]];
        file_put_contents("$this->rot/drift/oppdatering/tilstand.json", (string) json_encode(['plan' => $plan] + $tilstand));
        try {
            $this->kjerne()->oppdaterer()->kjor();
            self::fail('0.3.0 krever en PHP-versjon som ikke finnes.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.versjonsfil', $e->tekstnokkel);
        }
        self::assertSame('0.1.0', $this->versjon('bivrost/kjerne')['versjon']);
    }

    /**
     * Uten mellomstopp lastes ikke flere versjon.json ned enn før: appen venter på en nyere Kjerne, og reserven som
     * leter etter stopp, kjører ikke.
     */
    public function testUtenMellomstoppLastesIkkeFlereVersjonsfilerNed(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->lagApp("3.0.$i", ['app.php' => "<?php // 3.0.$i"], ['bivrost-kjerne' => '^0.9']);
        }
        file_put_contents($this->logg, '');
        $this->kjerne()->oppdaterer()->plan();
        $linjer = array_filter(Felt::ellers(file($this->logg, FILE_IGNORE_NEW_LINES), []), static fn (string $l): bool => str_contains($l, 'last-ned') && str_contains($l, 'versjon.json'));
        self::assertLessThanOrEqual(10, count($linjer), 'Høyst de fem nyeste per pakke.');
    }

    /**
     * <tag>.versjon.json hos GitHub er ikke signert. Sier den at versjonen kan nås, mens versjon.json i den signerte
     * pakken krever et mellomstopp, stopper hentingen før noe er endret.
     */
    public function testEndretVersjonsfilKanIkkeHoppeOverEtMellomstopp(): void
    {
        $this->lagKjerneMedStopp('0.4.0', ['src/A.php' => '<?php // 0.4'], ['php' => '>=8.2'], '>=0.2.0');
        $fil = "$this->pakker/test/bivrost/bivrost-kjerne-v0.4.0.versjon.json";
        $j = json_decode((string) file_get_contents($fil), true);
        self::assertIsArray($j);
        unset($j['oppdateres_fra']);
        file_put_contents($fil, (string) json_encode($j, JSON_UNESCAPED_SLASHES));
        // Appen 1.0.0 krever ^0.1. En appversjon som godtar 0.4, gjør at planen velger den endrede 0.4.0.
        $this->lagApp('1.9.0', ['app.php' => '<?php // 1.9', 'migrasjoner/001_a.sql' => 'CREATE TABLE IF NOT EXISTS testapp_a (id INT PRIMARY KEY);'], ['bivrost-kjerne' => '^0.4']);
        $plan = array_map(static fn (array $x): string => $x['pakke'] . ' ' . $x['til'], $this->kjerne()->oppdaterer()->plan());
        self::assertSame(['bivrost-kjerne 0.4.0', 'testapp 1.9.0'], $plan, 'Den endrede filen lurer planen.');
        try {
            $this->kjerne()->oppdaterer()->kjor();
            self::fail('Den signerte versjon.json krever et mellomstopp.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.versjonsfil', $e->tekstnokkel);
        }
        self::assertSame('0.1.0', $this->versjon('bivrost/kjerne')['versjon'], 'Ingenting er endret.');
    }

    /**
     * Svarer GitHub noe annet enn 200 på listen over versjoner, ser driftsbrukeren statuskoden i statusen, i
     * svaret fra steget (502 github_feil, ikke 500 intern_feil), i maskin-API-et og i loggen (overdragbarhet.md 12).
     */
    public function testStatuskodenFraGithubVisesNarListenFeiler(): void
    {
        foreach ([[['BIVROST_REPO' => 'test/finnes-ikke'], 404, 'privat'], [['BIVROST_GITHUB_TOKEN' => 'ugyldig'], 401, 'ugyldig eller utløpt']] as [$endring, $status, $hint]) {
            Env::skrivFil("$this->rot/.env", $endring + $this->env, overskriv: true);
            $k = $this->kjerne();
            $s = $k->oppdaterer()->status(true);
            self::assertSame('bivrost.feil.oppdatering.github', $s['plan_feil']);
            self::assertSame(['status' => $status], $s['plan_feil_verdier']);
            $tekst = $k->tekster()->tekst('bivrost.feil.oppdatering.github', ['status' => $status], 'nb');
            self::assertStringContainsString("(HTTP $status):", $tekst);
            self::assertStringContainsString($hint, $tekst);
            try {
                $this->kjerne()->oppdaterer()->kjor();
                self::fail('Steget skal stoppe når listen over versjoner feiler.');
            } catch (HttpFeil $e) {
                self::assertSame(502, $e->status);
                self::assertSame('github_feil', $e->kode);
                self::assertSame(['status' => $status], $e->verdier);
            }
            $feil = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:feil'));
            self::assertNotEmpty($feil);
            self::assertSame('plan', $feil[0]['hendelse']->data['steg'] ?? null);
            self::assertStringContainsString("HTTP $status", Felt::tekst($feil[0]['hendelse']->data['feil_tekst'] ?? null));
            self::assertSame('sikkerhet', $feil[0]['hendelse']->kategori['kode']);
        }

        // Maskin-API-et gir statuskoden som tall.
        $nokkel = $this->kjerne()->oppdateringsApi()->lagNokkel('Planlagt jobb');
        $svar = $this->kjerne()->handter(new Foresporsel('POST', '/bivrost/api/system/oppdatering/kjor', ['authorization' => "Bearer $nokkel"], vert: 'localhost'));
        self::assertSame(502, $svar->status, $svar->kropp);
        $j = json_decode($svar->kropp, true);
        self::assertIsArray($j);
        self::assertSame('github_feil', $j['kode']);
        self::assertSame(401, $j['github_status']);
    }

    public function testOppdateringsApiet(): void
    {
        $f = static fn (string $metode, string $sti, ?string $nokkel = null): Foresporsel => new Foresporsel(
            $metode,
            $sti,
            $nokkel === null ? [] : ['authorization' => "Bearer $nokkel"],
            vert: 'localhost',
            ip: '127.0.0.2'
        );
        $k = $this->kjerne();
        $k->settVent(static function (): void {
        });
        self::assertSame(404, $k->handter($f('GET', '/bivrost/api/system/oppdatering/status'))->status, 'Uten nøkkel: som om API-et ikke fantes.');
        $nokkel = $k->oppdateringsApi()->lagNokkel('GitHub Actions');
        self::assertSame(404, $this->kjerne()->handter($f('GET', '/bivrost/api/system/oppdatering/status', 'feil'))->status);
        // Mange feil nøkler fra én IP stenger ikke ute den riktige.
        for ($i = 0; $i < 11; $i++) {
            $this->kjerne()->handter($f('POST', '/bivrost/api/system/oppdatering/kjor', 'bivrost_feil' . $i));
        }
        $s = $this->kjerne()->handter($f('GET', '/bivrost/api/system/oppdatering/status', $nokkel));
        self::assertSame(200, $s->status);
        $j = json_decode($s->kropp, true);
        self::assertIsArray($j);
        self::assertTrue($j['ny_versjon']);
        self::assertSame([['id' => 'bivrost-kjerne', 'versjon' => '0.1.0'], ['id' => 'testapp', 'versjon' => '1.0.0']], $j['pakker']);
        self::assertStringNotContainsString('GitHub Actions', $s->kropp, 'Status gir ingen navn.');
        $resultater = [];
        $korr = null;
        for ($i = 0; $i < 6; $i++) {
            $hoder = ['authorization' => "Bearer $nokkel"] + ($korr !== null ? ['x-bivrost-korrelasjon' => $korr] : []);
            $svar = $this->kjerne()->handter(new Foresporsel('POST', '/bivrost/api/system/oppdatering/kjor', $hoder, vert: 'localhost'));
            $j = json_decode($svar->kropp, true);
            self::assertIsArray($j);
            self::assertSame(200, $svar->status, $svar->kropp);
            $resultater[] = $j['resultat'];
            $korr = is_string($j['korrelasjon']) ? $j['korrelasjon'] : null;
            if ($j['resultat'] === 'ingenting') {
                break;
            }
        }
        self::assertSame(['hentet', 'hentet', 'filer', 'database', 'ingenting'], $resultater);
        $h = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:filer'));
        self::assertSame('Oppdateringsnøkkel «GitHub Actions»', $h[0]['hendelse']->hvem['navn'], 'Loggen sier hvilken nøkkel som gjorde det.');
        self::assertSame('api', $h[0]['hendelse']->kilde['kode']);
        $avvist = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:api:avvist'));
        self::assertNotEmpty($avvist);
    }
}
