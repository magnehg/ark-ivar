<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Integrasjon;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Preferanser\Preferanser;
use Bivrost\Kjerne\Preferanser\Preferansetjeneste;
use Bivrost\Kjerne\Preferanser\SentralPreferanser;
use Bivrost\Kjerne\Sikkerhetskopi\Databasekopi;
use Bivrost\Kjerne\Sikkerhetskopi\Filkopi;
use Bivrost\Kjerne\Sikkerhetskopi\Kopilager;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\Mappe;

final class KopiOgPreferanserTest extends DbTestCase
{
    private string $m = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = Mappe::ny();
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->m);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function sjekksummer(): array
    {
        $ut = [];
        foreach (['bivrost_x', 'app_y'] as $t) {
            $r = $this->db->en("CHECKSUM TABLE `$t` EXTENDED");
            $ut[$t] = Felt::tekst($r['Checksum'] ?? '');
        }

        return $ut;
    }

    public function testKopiTarBareEgneTabellerOgGirEksakteVerdierTilbake(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("CREATE TABLE bivrost_x (id INT PRIMARY KEY, d DOUBLE, dec_ DECIMAL(30,10), b VARBINARY(20), bl BLOB, bit_ BIT(5), t TEXT, j JSON) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO bivrost_x VALUES (1, 0.1 + 0.2, 12345678901234567890.0123456789, UNHEX('00FF10'), UNHEX('DEADBEEF00'), b'10101', 'linje1\\nlinje2; \\'sitat\\' æøå \\\\', '{\"a\": [1, 2]}'), (2, 1.7976931348623157e308, -1, '', NULL, b'0', '', NULL)");
        $pdo->exec('CREATE TABLE app_y (id BIGINT UNSIGNED PRIMARY KEY, v VARCHAR(40)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO app_y VALUES (18446744073709551615, '-- bivrost:setning')");
        $pdo->exec('CREATE TABLE annen_app (id INT)');
        $pdo->exec('INSERT INTO annen_app VALUES (7)');
        $for = $this->sjekksummer();
        $lager = new Kopilager("$this->m/sikkerhetskopier");
        $k = new Databasekopi($this->db, ['bivrost_', 'app_'], $lager);
        self::assertSame(['app_y', 'bivrost_x'], $k->tabeller());
        $navn = $k->ta();
        self::assertMatchesRegularExpression(Kopilager::MONSTER['db'], $navn);
        self::assertSame(0600, fileperms("$this->m/sikkerhetskopier/$navn") & 0777);
        $innhold = (string) gzdecode((string) file_get_contents("$this->m/sikkerhetskopier/$navn"));
        self::assertStringNotContainsString('annen_app', $innhold, 'Andre programmers tabeller er ikke med.');

        $pdo->exec("UPDATE bivrost_x SET t = 'endret'");
        $pdo->exec('CREATE TABLE bivrost_ny (id INT)');
        $pdo->exec('DELETE FROM annen_app');
        $k->lesInn($navn);
        self::assertSame($for, $this->sjekksummer());
        self::assertFalse($this->db->harTabell('bivrost_ny'), 'En egen tabell fra en nyere versjon fjernes.');
        self::assertSame(0, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM annen_app')), 'Andre tabeller røres ikke av innlesingen.');
        self::assertFileExists("$this->m/.htaccess");
    }

    public function testFilkopiOgTilbakeleggingFjernerNyeFiler(): void
    {
        Mappe::skriv("$this->m/rot/app/a.php", 'a');
        Mappe::skriv("$this->m/rot/app/u/b.php", 'b');
        Mappe::skriv("$this->m/rot/annet/c.php", 'c');
        $lager = new Kopilager("$this->m/sikkerhetskopier");
        $f = new Filkopi("$this->m/rot", $lager);
        $navn = $f->ta(['app/']);
        file_put_contents("$this->m/rot/app/a.php", 'endret');
        Mappe::skriv("$this->m/rot/app/ny/n.php", 'ny');
        unlink("$this->m/rot/app/u/b.php");
        self::assertSame([], $f->leggTilbake($navn, ['app/']));
        self::assertSame('a', file_get_contents("$this->m/rot/app/a.php"));
        self::assertSame('b', file_get_contents("$this->m/rot/app/u/b.php"));
        self::assertFileDoesNotExist("$this->m/rot/app/ny/n.php");
        self::assertSame('c', file_get_contents("$this->m/rot/annet/c.php"));
    }

    public function testOppryddingBeholderDeTiNyesteOgDeUnge(): void
    {
        $lager = new Kopilager("$this->m/k");
        $lager->mappe();
        for ($i = 0; $i < 14; $i++) {
            $n = sprintf('db-202601%02d-000000-%08x.sql.gz', $i + 1, $i);
            file_put_contents("$this->m/k/$n", 'x');
            touch("$this->m/k/$n", strtotime('2026-01-01') + $i * 86400);
        }
        touch("$this->m/k/db-20260114-000000-0000000d.sql.gz", time());
        $slettet = $lager->rydd(['db-20260101-000000-00000000.sql.gz']);
        self::assertSame(['db-20260104-000000-00000003.sql.gz', 'db-20260103-000000-00000002.sql.gz', 'db-20260102-000000-00000001.sql.gz'], $slettet);
        self::assertNotContains('db-20260101-000000-00000000.sql.gz', $slettet, 'Vernet kopi slettes ikke.');
        $this->expectException(\Bivrost\Kjerne\Feil\BivrostFeil::class);
        $lager->sti('../.env');
    }

    public function testPreferanserFraSentralLokalEndringOgVentendeSynk(): void
    {
        $this->kjerneskjema();
        $sentral = new class implements SentralPreferanser {
            public bool $oppe = true;
            public ?Preferanser $lagret = null;

            public function hent(string $tilgangstoken): ?Preferanser
            {
                return $this->oppe ? $this->lagret : null;
            }

            public function lagre(string $tilgangstoken, Preferanser $felles): bool
            {
                if (!$this->oppe) {
                    return false;
                }
                $this->lagret = $felles;

                return true;
            }
        };
        $p = new Preferansetjeneste($this->db, $sentral);
        $p->fraInnlogging('sub-1', ['tema' => 'mose', 'modus' => 'mork', 'oppdatert' => '2026-11-13T09:00:00Z', 'ugyldig' => 'x'], 'tok');
        self::assertSame('mose', $p->effektive('sub-1')->hent('tema'));
        self::assertSame('begrenset', $p->effektive('sub-1')->hent('bredde'));
        $p->endreApp('sub-1', ['modus' => 'lys']);
        self::assertSame('lys', $p->effektive('sub-1')->hent('modus'));
        self::assertSame(['tema' => 'felles', 'modus' => 'app', 'bredde' => 'system'], array_intersect_key($p->kilder('sub-1'), ['tema' => 1, 'modus' => 1, 'bredde' => 1]));

        $sentral->oppe = false;
        self::assertFalse($p->endreFelles('sub-1', ['tema' => 'vinrod'], 'tok'), 'Sentral er nede: endringen venter lokalt.');
        self::assertSame('vinrod', $p->effektive('sub-1')->hent('tema'), 'Utseendet faller ikke tilbake når Sentral er nede.');
        self::assertFalse($p->lagret('sub-1')['synkronisert']);

        // Neste innlogging med et eldre tidsstempel fra Sentral: den lokale, nyere endringen sendes og beholdes.
        $sentral->oppe = true;
        $p->fraInnlogging('sub-1', ['tema' => 'mose', 'oppdatert' => '2020-01-01T00:00:00Z'], 'tok');
        self::assertSame('vinrod', $p->effektive('sub-1')->hent('tema'));
        self::assertSame('vinrod', $sentral->lagret?->hent('tema'));
        self::assertTrue($p->lagret('sub-1')['synkronisert']);

        // En nyere endring hos Sentral (gjort i en annen app) vinner.
        $p->fraInnlogging('sub-1', ['tema' => 'teal', 'oppdatert' => '2099-01-01T00:00:00Z'], 'tok');
        self::assertSame('teal', $p->effektive('sub-1')->hent('tema'));
        self::assertSame('lys', $p->effektive('sub-1')->hent('modus'), 'Appens eget valg overstyrer fortsatt.');
        $this->expectException(\InvalidArgumentException::class);
        $p->endreFelles('sub-1', ['tema' => 'rosa'], null);
    }
}
