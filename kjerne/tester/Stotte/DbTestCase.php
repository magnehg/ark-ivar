<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\SqlDeler;
use PHPUnit\Framework\TestCase;

/** Grunnlag for testene mot MariaDB/MySQL: én ny database per test, slettet etterpå. */
abstract class DbTestCase extends TestCase
{
    protected string $dbNavn = '';
    protected Db $db;

    protected function setUp(): void
    {
        if (!TestDb::tilgjengelig()) {
            if (getenv('BIVROST_TEST_KREV_DB') === '1') {
                self::fail('Testdatabasen kreves (BIVROST_TEST_KREV_DB=1), men svarer ikke.');
            }
            self::markTestSkipped('Ingen testdatabase (start MariaDB, eller sett BIVROST_TEST_DB_*).');
        }
        $this->dbNavn = TestDb::ny();
        $this->db = TestDb::kobleTil($this->dbNavn);
    }

    protected function tearDown(): void
    {
        // Testene lager mange Kjerne-objekter (ett per steg, som på et webhotell). De inngår i referansesykluser, så
        // databasetilkoblingene lukkes først når PHP rydder sykluser. Uten ryddingen her nådde testene 161 samtidige
        // tilkoblinger, over max_connections (151 som standard i MariaDB og MySQL). I drift lever Kjerne én forespørsel.
        gc_collect_cycles();
        if ($this->dbNavn !== '') {
            TestDb::slett($this->dbNavn);
        }
    }

    protected function kjerneskjema(): void
    {
        SqlDeler::kjor($this->db, (string) file_get_contents(__DIR__ . '/../../migrasjoner/001_grunnlag.sql'));
    }
}
