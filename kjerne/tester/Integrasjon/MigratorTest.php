<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Integrasjon;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Migrering\Migrator;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\Mappe;

final class MigratorTest extends DbTestCase
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

    public function testKjorerIRekkefolgeEgenRekkePerPakkeOgIdempotent(): void
    {
        file_put_contents("$this->m/001_a.sql", 'CREATE TABLE IF NOT EXISTS app_a (id INT);');
        file_put_contents("$this->m/002_b.sql", "CREATE TABLE IF NOT EXISTS app_b (t VARCHAR(10) DEFAULT 'x;y'); INSERT INTO app_b VALUES ('z');");
        $a = new Migrator($this->db, 'app', $this->m);
        self::assertSame([['nr' => 1, 'navn' => 'a'], ['nr' => 2, 'navn' => 'b']], $a->kjor());
        self::assertSame([], $a->kjor());
        self::assertSame(2, $a->status()['versjon']);
        $k = new Migrator($this->db, 'bivrost-kjerne', __DIR__ . '/../../migrasjoner');
        self::assertCount(1, $k->kjor());
        self::assertSame(1, $k->status()['versjon']);
        self::assertSame(2, $a->status()['versjon'], 'Hver pakke har sin egen skjemaversjon.');
        // Kjernens migrering kan kjøres en gang til (IF NOT EXISTS).
        \Bivrost\Kjerne\Database\SqlDeler::kjor($this->db, (string) file_get_contents(__DIR__ . '/../../migrasjoner/001_grunnlag.sql'));
    }

    public function testEnUtlagtMigreringSomErEndretStopperAlt(): void
    {
        file_put_contents("$this->m/001_a.sql", 'CREATE TABLE IF NOT EXISTS app_a (id INT);');
        $a = new Migrator($this->db, 'app', $this->m);
        $a->kjor();
        file_put_contents("$this->m/001_a.sql", 'CREATE TABLE IF NOT EXISTS app_a (id BIGINT);');
        file_put_contents("$this->m/002_b.sql", 'CREATE TABLE IF NOT EXISTS app_b (id INT);');
        self::assertSame(['endret:001_a.sql'], $a->status()['feil']);
        self::assertSame([], $a->status()['ventende']);
        $this->expectException(BivrostFeil::class);
        $a->kjor();
    }

    public function testFeilNavnOgDobleNumreStopperForNoeKjores(): void
    {
        file_put_contents("$this->m/001_a.sql", 'CREATE TABLE app_a (id INT);');
        file_put_contents("$this->m/1_feil.sql", 'SELECT 1;');
        $a = new Migrator($this->db, 'app', $this->m);
        try {
            $a->kjor();
            self::fail('Feil navn skal stoppe kjøringen.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.migrering.filer', $e->tekstnokkel);
        }
        self::assertFalse($this->db->harTabell('app_a'));
        unlink("$this->m/1_feil.sql");
        file_put_contents("$this->m/001_b.sql", 'SELECT 1;');
        self::assertContains('dobbel:1', $a->status()['feil']);
    }

    public function testFeilendeMigreringLagresIkkeOgKopiKallesForst(): void
    {
        file_put_contents("$this->m/001_a.sql", 'CREATE TABLE IF NOT EXISTS app_a (id INT);');
        file_put_contents("$this->m/002_feil.sql", 'SELECT * FROM finnes_ikke;');
        $a = new Migrator($this->db, 'app', $this->m);
        $kalt = 0;
        try {
            $a->kjor(function () use (&$kalt): void {
                $kalt++;
            });
            self::fail('Skulle feilet.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.migrering.feilet', $e->tekstnokkel);
        }
        self::assertSame(1, $kalt);
        self::assertSame(1, $a->status()['versjon']);
        self::assertSame([['nr' => 2, 'navn' => 'feil']], $a->status()['ventende']);
    }

    public function testLasHindrerSamtidigKjoring(): void
    {
        file_put_contents("$this->m/001_a.sql", 'SELECT 1;');
        $annen = \Bivrost\Kjerne\Tester\Stotte\TestDb::kobleTil($this->dbNavn);
        self::assertTrue($annen->las('migr:app', 1));
        try {
            (new Migrator($this->db, 'app', $this->m, 1))->kjor();
            self::fail('Låsen skal hindre en ny kjøring.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.migrering.pagar', $e->tekstnokkel);
        } finally {
            $annen->slippLas('migr:app');
        }
    }
}
