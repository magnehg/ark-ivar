<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\EndeTilEnde;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Kjerne;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Oppdatering\Pakkeleser;
use Bivrost\Kjerne\Oppdatering\Signatur;
use Bivrost\Kjerne\Oppdatering\Tilbakerulling;
use Bivrost\Kjerne\Oppdatering\Utgivernokler;
use Bivrost\Kjerne\Sikkerhetskopi\Databasekopi;
use Bivrost\Kjerne\Sikkerhetskopi\Filkopi;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\PakkeBygger;
use Bivrost\Kjerne\Tester\Stotte\TestDb;
use Bivrost\Kjerne\Tester\Stotte\Tjener;

/**
 * Obligatorisk signatur med tillit ved første bruk (BIV-DRF-14, BIV-B-034), ende til ende mot et etterlignet GitHub:
 * utgivernøkkelen i pakken klareres første gang, usignerte og feilsignerte pakker avvises etterpå, et bytte må være
 * signert med den klarerte nøkkelen (eller driften setter den nye i .env), nøkkelen i .env går foran, og et bytte går
 * bare fremover (en tilbakerulling beholder den klarerte nøkkelen).
 */
final class UtgivernokkelTest extends DbTestCase
{
    private const PAKKE = 'bivrost-kjerne';

    private string $m = '';
    private string $rot = '';
    private string $utgivelser = '';
    private ?Tjener $github = null;

    /** @var array{offentlig: string, hemmelig: string} */
    private array $a;

    /** @var array{offentlig: string, hemmelig: string} */
    private array $b;

    /** @var array<string, string> */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = Mappe::ny();
        $this->rot = "$this->m/rot";
        $this->utgivelser = "$this->m/pakker/test/bivrost";
        mkdir($this->rot);
        mkdir($this->utgivelser, 0755, true);
        $this->a = Signatur::nyttNokkelpar();
        $this->b = Signatur::nyttNokkelpar();
        $this->github = new Tjener(__DIR__ . '/../Stotte/github-ruter.php', ['MOCK_PAKKER' => "$this->m/pakker", 'MOCK_LOGG' => "$this->m/github.jsonl"]);
        $this->env = TestDb::env($this->dbNavn) + [
            'BIVROST_KRYPTERINGSNOKKEL' => Hemmelighetsboks::nyNokkel(),
            'BIVROST_HMAC_NOKKEL' => 'hmac-test',
            'BIVROST_ADRESSE' => 'http://localhost',
            'BIVROST_TILLAT_HTTP_LOKALT' => '1',
            'GITHUB_API_URL' => 'http://127.0.0.1:' . $this->github->port,
            'BIVROST_REPO' => 'test/bivrost',
        ];
        Env::skrivFil("$this->rot/.env", $this->env);
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

        return new Kjerne(AppKonfig::fraListe(['app' => 'testapp', 'appnavn' => 'Testappen', 'rot' => $this->rot]), new FastKlokke());
    }

    /** Lager en versjon av Kjerne med utgivernøkkelen $nokkel i pakken (null = ingen), signert med $signer (null = usignert). */
    private function lag(string $versjon, ?string $nokkel, ?string $signer): void
    {
        $gamle = glob("$this->utgivelser/bivrost-kjerne-v$versjon.*");
        foreach ($gamle === false ? [] : $gamle as $f) {
            unlink($f);
        }
        $filer = ['src/A.php' => "<?php // $versjon"] + ($nokkel === null ? [] : ['signeringsnokkel.offentlig' => $nokkel . "\n"]);
        PakkeBygger::lag($this->utgivelser, self::PAKKE, 'bivrost-kjerne-v', 'bivrost/kjerne/', $versjon, $filer, ['php' => '>=8.2'], $signer);
    }

    /** Installasjonen med Kjerne 0.1.0 pakket ut, som før denne endringen (ingen klarert nøkkel i databasen). */
    private function installer(?string $nokkel): void
    {
        $this->lag('0.1.0', $nokkel, null);
        $z = new \ZipArchive();
        $z->open("$this->utgivelser/bivrost-kjerne-v0.1.0.zip");
        $z->extractTo($this->rot);
        $z->close();
        $this->kjerne()->migrerAlle();
        file_put_contents("$this->rot/drift/installert.json", '{}');
    }

    /** @return list<string> Stegene til det ikke er mer å gjøre (ny Kjerne per kall, som på et webhotell). */
    private function oppdater(): array
    {
        $steg = [];
        for ($i = 0; $i < 6; $i++) {
            $steg[] = $r = $this->kjerne()->oppdaterer()->kjor()['resultat'];
            if ($r === 'ingenting') {
                break;
            }
        }

        return $steg;
    }

    private function avvist(string $feil): void
    {
        try {
            $this->oppdater();
            self::fail("Ventet $feil.");
        } catch (BivrostFeil $e) {
            self::assertSame($feil, $e->tekstnokkel);
        }
        self::assertNull($this->kjerne()->oppdaterer()->status(false)['pagaende'], 'Tilstanden er ryddet.');
    }

    private function versjon(): string
    {
        $v = Pakkeleser::json("$this->rot/bivrost/kjerne/versjon.json")['versjon'] ?? null;

        return is_string($v) ? $v : '';
    }

    /** @return array{nokkel: string, kilde: string, versjon: ?string, klarert: string, fingeravtrykk: string}|null */
    private function klarert(): ?array
    {
        return (new Utgivernokler($this->db))->hent(self::PAKKE);
    }

    /** @return array{signatur_kreves: mixed, kilde: mixed, fingeravtrykk: mixed, feil: mixed} Statusen til Kjerne. */
    private function nokkelstatus(): array
    {
        $pakker = $this->kjerne()->oppdaterer()->status(false)['pakker'];
        self::assertIsArray($pakker);
        self::assertIsArray($pakker[0]);
        $u = $pakker[0]['utgivernokkel'];
        self::assertIsArray($u);

        return ['signatur_kreves' => $pakker[0]['signatur_kreves'], 'kilde' => $u['kilde'], 'fingeravtrykk' => $u['fingeravtrykk'], 'feil' => $u['feil']];
    }

    private function tilbakerulling(): Tilbakerulling
    {
        $k = $this->kjerne();
        $lager = $k->kopilager();

        return new Tilbakerulling("$this->rot/drift", $this->db, $k->logg(), new Databasekopi($this->db, ['bivrost_'], $lager), new Filkopi($this->rot, $lager), $lager, $k->oppdaterer(), new Utgivernokler($this->db));
    }

    private function settEnv(?string $nokkel): void
    {
        $env = $this->env;
        if ($nokkel !== null) {
            $env['BIVROST_OFFENTLIG_NOKKEL'] = $nokkel;
        }
        Env::skrivFil("$this->rot/.env", $env, '', true);
    }

    public function testForsteOppdateringSomHarNokkelenKlarererDen(): void
    {
        $this->installer(null);
        self::assertFalse($this->nokkelstatus()['signatur_kreves'], 'Ingen nøkkel ennå: som før.');
        $this->lag('0.2.0', $this->a['offentlig'], $this->a['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        self::assertSame('0.2.0', $this->versjon());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($this->a['offentlig'], $k['nokkel']);
        self::assertSame('oppdatering', $k['kilde']);
        self::assertSame('0.2.0', $k['versjon']);
        $h = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:nokkel'));
        self::assertCount(1, $h);
        self::assertSame('Utgivernøkkel klarert', $h[0]['hendelse']->hva['tekst']);
        self::assertSame(Utgivernokler::fingeravtrykk($this->a['offentlig']), $h[0]['hendelse']->etter['fingeravtrykk']['tekst']);
        self::assertSame([], $h[0]['hendelse']->for);
        $hentet = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:hentet'));
        self::assertTrue($hentet[0]['hendelse']->data['signert'] ?? null);
        $s = $this->nokkelstatus();
        self::assertTrue($s['signatur_kreves']);
        self::assertSame('klarert', $s['kilde']);
        self::assertSame(Utgivernokler::fingeravtrykk($this->a['offentlig']), $s['fingeravtrykk']);

        // Fra nå av kreves signatur med den klarerte nøkkelen.
        $this->lag('0.3.0', $this->a['offentlig'], null);
        $this->avvist('bivrost.feil.oppdatering.usignert');
        self::assertSame('0.2.0', $this->versjon());
    }

    public function testInstallasjonFraForNokkelenKlarererDeInstallerteFilene(): void
    {
        $this->installer($this->a['offentlig']);
        self::assertSame('installert', $this->nokkelstatus()['kilde'], 'Klareres ved neste oppdatering.');
        self::assertNull($this->klarert(), 'Statusen endrer ingenting.');
        self::assertSame(['ingenting'], $this->oppdater());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($this->a['offentlig'], $k['nokkel']);
        self::assertSame('installert', $k['kilde']);
        self::assertSame('0.1.0', $k['versjon']);
    }

    public function testUsignertPakkeAvvisesNaarEnNokkelErKlarert(): void
    {
        $this->installer($this->a['offentlig']);
        $this->lag('0.2.0', $this->a['offentlig'], null);
        $this->avvist('bivrost.feil.oppdatering.usignert');
        $this->lag('0.2.0', null, null);
        $this->avvist('bivrost.feil.oppdatering.usignert');
        self::assertSame('0.1.0', $this->versjon());
        $feil = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:feil'));
        self::assertSame('bivrost.feil.oppdatering.usignert', $feil[0]['hendelse']->data['feil'] ?? null);
    }

    public function testPakkeSignertMedFeilNokkelAvvises(): void
    {
        $this->installer($this->a['offentlig']);
        $this->lag('0.2.0', $this->a['offentlig'], $this->b['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.signatur');
        $this->lag('0.2.0', null, $this->b['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.signatur');
        self::assertSame('0.1.0', $this->versjon());
        self::assertSame($this->a['offentlig'], $this->klarert()['nokkel'] ?? null);
    }

    public function testForsteNokkelSomIkkeHarSignertPakkenAvvises(): void
    {
        $this->installer(null);
        $this->lag('0.2.0', $this->a['offentlig'], null);
        $this->avvist('bivrost.feil.oppdatering.nokkel_usignert');
        $this->lag('0.2.0', $this->a['offentlig'], $this->b['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.nokkel_usignert');
        self::assertSame('0.1.0', $this->versjon());
        self::assertNull($this->klarert());
    }

    public function testByttePaNokkelSignertMedDenKlarerteGodtasOgGaarBareFremover(): void
    {
        $this->installer($this->a['offentlig']);
        $this->lag('0.2.0', $this->b['offentlig'], $this->a['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        self::assertSame('0.2.0', $this->versjon());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($this->b['offentlig'], $k['nokkel']);
        self::assertSame('bytte', $k['kilde']);
        $h = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:nokkel'));
        self::assertCount(2, $h, 'De installerte filene, så byttet.');
        $bytte = $h[0]['hendelse']; // nyeste først
        self::assertSame(Utgivernokler::fingeravtrykk($this->a['offentlig']), $bytte->for['fingeravtrykk']['tekst'] ?? null);
        self::assertSame(Utgivernokler::fingeravtrykk($this->b['offentlig']), $bytte->etter['fingeravtrykk']['tekst'] ?? null);
        self::assertSame('bytte', $bytte->data['kilde'] ?? null);

        // Etter byttet godtas bare den nye nøkkelen.
        $this->lag('0.3.0', $this->b['offentlig'], $this->a['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.signatur');
        $this->lag('0.3.0', $this->b['offentlig'], $this->b['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        self::assertSame('0.3.0', $this->versjon());

        // Et bytte går bare fremover: tilbake til punktet før byttet gir filene fra 0.1.0, men den nye nøkkelen er
        // fortsatt klarert, så den gamle (som kan være lekket) aldri godtas igjen.
        $t = $this->tilbakerulling();
        $punkter = $t->punkter();
        self::assertCount(2, $punkter);
        $forBytte = array_values(array_filter($punkter, static fn (array $p): bool => $p['pakker'][0]['versjon'] === '0.1.0'));
        self::assertCount(1, $forBytte);
        $r = $t->tilbakerull($forBytte[0]['navn']);
        self::assertSame([], $r['avvik']);
        self::assertSame('0.1.0', $this->versjon());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($this->b['offentlig'], $k['nokkel']);
        self::assertSame('bytte', $k['kilde']);
        $rullet = $this->kjerne()->logg()->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:oppdatering:tilbakerullet'));
        self::assertSame(1, $rullet[0]['hendelse']->data['utgivernokler_beholdt'] ?? null);
        $this->lag('0.4.0', $this->b['offentlig'], $this->a['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.signatur');
        self::assertSame('0.1.0', $this->versjon());
        $this->lag('0.4.0', $this->b['offentlig'], $this->b['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        self::assertSame('0.4.0', $this->versjon());
    }

    public function testTilbakerullingTilForDenForsteNokkelenBeholderSignaturkravet(): void
    {
        $this->installer(null);
        $this->lag('0.2.0', $this->a['offentlig'], $this->a['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        $t = $this->tilbakerulling();
        $punkter = $t->punkter();
        self::assertCount(1, $punkter);
        self::assertSame([], $t->tilbakerull($punkter[0]['navn'])['avvik']);
        self::assertSame('0.1.0', $this->versjon());
        self::assertSame($this->a['offentlig'], $this->klarert()['nokkel'] ?? null, 'Signaturkravet faller ikke bort.');
        $this->lag('0.3.0', null, null);
        $this->avvist('bivrost.feil.oppdatering.usignert');
    }

    public function testByttePaNokkelSomIkkeErSignertMedDenKlarerteAvvises(): void
    {
        $this->installer($this->a['offentlig']);
        $this->kjerne()->oppdaterer()->klarerInstallerte('installert');
        $this->lag('0.2.0', $this->b['offentlig'], $this->b['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.nokkel_byttet');
        self::assertSame('0.1.0', $this->versjon());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($this->a['offentlig'], $k['nokkel']);
        self::assertSame('installert', $k['kilde']);
        self::assertFileDoesNotExist("$this->rot/drift/oppdatering/klar/bivrost-kjerne.json", 'Arbeidsmappen er ryddet.');
    }

    public function testNokkelenIEnvGaarForanDenKlarerte(): void
    {
        $this->installer($this->a['offentlig']);
        $c = Signatur::nyttNokkelpar();
        $this->settEnv($c['offentlig']);
        self::assertSame('env', $this->nokkelstatus()['kilde']);

        // Signert med den klarerte, men .env sier noe annet: avvist.
        $this->lag('0.2.0', $this->a['offentlig'], $this->a['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.signatur');
        self::assertSame($this->a['offentlig'], $this->klarert()['nokkel'] ?? null, 'Den klarerte er lagret ved første kjøring.');

        // Driftens bytte: den nye nøkkelen i .env, og en pakke som er signert med den og har den.
        $this->lag('0.2.0', $c['offentlig'], $c['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($c['offentlig'], $k['nokkel']);
        self::assertSame('env', $k['kilde']);

        // Linjen i .env kan fjernes etterpå: den klarerte er nå den nye.
        $this->settEnv(null);
        $this->lag('0.3.0', $c['offentlig'], $c['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        self::assertSame('0.3.0', $this->versjon());

        // En ugyldig nøkkel i .env stopper alt (aldri «ingen nøkkel»).
        $this->settEnv('ikke en nøkkel');
        $this->lag('0.4.0', $c['offentlig'], $c['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.signaturnokkel');
        self::assertSame('bivrost.feil.oppdatering.signaturnokkel', $this->nokkelstatus()['feil']);
        self::assertTrue($this->nokkelstatus()['signatur_kreves']);
    }

    public function testPakkeSignertMedEnvNokkelenKanIkkeKlarereEnTredjeNokkel(): void
    {
        $this->installer($this->a['offentlig']);
        $c = Signatur::nyttNokkelpar();
        $this->settEnv($c['offentlig']);
        // Signert med nøkkelen i .env, men har med seg en tredje nøkkel: et bytte som verken er signert med den
        // klarerte eller er nøkkelen driften har satt.
        $this->lag('0.2.0', $this->b['offentlig'], $c['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.nokkel_byttet');
        self::assertSame('0.1.0', $this->versjon());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($this->a['offentlig'], $k['nokkel']);
        self::assertSame('installert', $k['kilde']);
    }

    public function testNokkelenSomKlareresMaaVaereDenIArbeidsmappen(): void
    {
        $this->installer(null);
        $this->lag('0.2.0', $this->a['offentlig'], $this->a['hemmelig']);
        self::assertSame('hentet', $this->kjerne()->oppdaterer()->kjor()['resultat']);
        // Tilstandsfilen endres mellom kallene: en annen nøkkel enn den som ble kontrollert i hent-steget.
        $fil = "$this->rot/drift/oppdatering/klar/bivrost-kjerne.json";
        $klar = json_decode((string) file_get_contents($fil), true);
        self::assertIsArray($klar);
        self::assertIsArray($klar['klarer']);
        self::assertSame($this->a['offentlig'], $klar['klarer']['nokkel']);
        $klar['klarer']['nokkel'] = $this->b['offentlig'];
        file_put_contents($fil, json_encode($klar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        try {
            $this->kjerne()->oppdaterer()->kjor();
            self::fail('Ventet arbeid_endret.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.arbeid_endret', $e->tekstnokkel);
        }
        self::assertNull($this->klarert(), 'Ingen nøkkel er klarert.');
        self::assertSame('0.1.0', $this->versjon());
    }

    public function testPakkeKontrollertMedEnvKlarererNokkelenDenHarMedSeg(): void
    {
        $this->installer(null);
        $c = Signatur::nyttNokkelpar();
        $this->settEnv($c['offentlig']);
        $this->lag('0.2.0', $this->a['offentlig'], $c['hemmelig']);
        self::assertSame(['hentet', 'filer', 'database', 'ingenting'], $this->oppdater());
        $k = $this->klarert();
        self::assertIsArray($k);
        self::assertSame($this->a['offentlig'], $k['nokkel'], 'Driften går god for pakken med nøkkelen i .env.');
        self::assertSame('oppdatering', $k['kilde']);
        // .env går fortsatt foran: en pakke signert bare med den klarerte avvises.
        $this->lag('0.3.0', $this->a['offentlig'], $this->a['hemmelig']);
        $this->avvist('bivrost.feil.oppdatering.signatur');
    }

    public function testOdelagtNokkelfilIPakkenAvvises(): void
    {
        $this->installer(null);
        $this->lag('0.2.0', 'ikke-en-nøkkel', $this->a['hemmelig']);
        try {
            $this->oppdater();
            self::fail('Ventet nokkelfil.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.nokkelfil', $e->tekstnokkel);
            self::assertSame(['pakke' => self::PAKKE, 'fil' => 'bivrost-kjerne-v0.2.0.zip:bivrost/kjerne/signeringsnokkel.offentlig'], $e->verdier);
        }
        self::assertSame('0.1.0', $this->versjon());
    }

    public function testOdelagtInstallertNokkelfilSierHvilkenFilDetGjelder(): void
    {
        $this->installer(null);
        file_put_contents("$this->rot/bivrost/kjerne/signeringsnokkel.offentlig", 'x');
        try {
            $this->oppdater();
            self::fail('Ventet nokkelfil.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.oppdatering.nokkelfil', $e->tekstnokkel);
            self::assertSame(['pakke' => self::PAKKE, 'fil' => 'bivrost/kjerne/signeringsnokkel.offentlig'], $e->verdier);
        }
        self::assertSame('bivrost.feil.oppdatering.nokkelfil', $this->nokkelstatus()['feil']);
    }
}
