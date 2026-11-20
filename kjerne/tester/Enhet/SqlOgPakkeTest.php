<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Database\SqlDeler;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Oppdatering\Pakke;
use Bivrost\Kjerne\Oppdatering\Pakkeleser;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\PakkeBygger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlOgPakkeTest extends TestCase
{
    public function testDelerSqlUtenforTeksterOgKommentarer(): void
    {
        $sql = "\xEF\xBB\xBF-- kommentar; med semikolon\nCREATE TABLE a (b TEXT COMMENT 'x;y'); # mer\n/* blokk; */INSERT INTO a VALUES ('it''s;', \"q\\\";\");\n/*!40101 SET x=1 */;--\nSELECT `a;b` FROM a";
        self::assertSame([
            "CREATE TABLE a (b TEXT COMMENT 'x;y')",
            "INSERT INTO a VALUES ('it''s;', \"q\\\";\")",
            '/*!40101 SET x=1 */',
            'SELECT `a;b` FROM a',
        ], SqlDeler::del($sql));
    }

    public function testAvviserDelimiterOgUavsluttet(): void
    {
        foreach (["DELIMITER //\nSELECT 1", "SELECT 'uten slutt", 'SELECT 1 /* uten slutt'] as $s) {
            try {
                SqlDeler::del($s);
                self::fail($s);
            } catch (BivrostFeil $e) {
                self::assertStringStartsWith('bivrost.feil.sql.', $e->tekstnokkel);
            }
        }
    }

    private function pakke(): Pakke
    {
        return new Pakke('testapp', 'Testapp', 'eier/testapp', 'testapp-v', 'testapp/', null, ['testapp/public/.htaccess']);
    }

    public function testGyldigPakkePakkesUtOgKontrolleres(): void
    {
        $m = Mappe::ny();
        try {
            $tag = PakkeBygger::lag($m, 'testapp', 'testapp-v', 'testapp/', '1.0.0', ['a.php' => '<?php', 'public/.htaccess' => 'Require all granted', 'undermappe/b.txt' => 'b'], ['bivrost-kjerne' => '^0.1']);
            mkdir("$m/ut");
            $r = Pakkeleser::pakkUt($this->pakke(), "$m/$tag.zip", $tag, '1.0.0', "$m/ut");
            self::assertSame('^0.1', is_array($r['versjon']['krever']) ? $r['versjon']['krever']['bivrost-kjerne'] : null);
            self::assertArrayHasKey('testapp/undermappe/b.txt', $r['filer']);
            self::assertSame('b', file_get_contents("$m/ut/testapp/undermappe/b.txt"));
        } finally {
            Mappe::slett($m);
        }
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function farligePakker(): iterable
    {
        yield 'utenfor mappen' => [['annet/x.php' => 'x'], 'bivrost.feil.oppdatering.pakke_navn'];
        yield 'opp en mappe' => [['testapp/../x.php' => 'x'], 'bivrost.feil.oppdatering.pakke_navn'];
        yield 'absolutt sti' => [['/etc/x' => 'x'], 'bivrost.feil.oppdatering.pakke_navn'];
        yield 'omvendt skråstrek' => [['testapp\\x.php' => 'x'], 'bivrost.feil.oppdatering.pakke_navn'];
        yield '.env' => [['testapp/.env' => 'DB_PASSORD=x'], 'bivrost.feil.oppdatering.pakke_oppsett'];
        yield '.htaccess uten lov' => [['testapp/.htaccess' => 'x'], 'bivrost.feil.oppdatering.pakke_oppsett'];
        yield 'user.ini' => [['testapp/public/.user.ini' => 'x'], 'bivrost.feil.oppdatering.pakke_oppsett'];
        yield 'fil utenfor manifestet' => [['testapp/ekstra.php' => 'x'], 'bivrost.feil.oppdatering.manifest'];
    }

    /** @param array<string, string> $ekstra */
    #[DataProvider('farligePakker')]
    public function testFarligePakkerAvvisesHelt(array $ekstra, string $feil): void
    {
        $m = Mappe::ny();
        try {
            $tag = PakkeBygger::lag($m, 'testapp', 'testapp-v', 'testapp/', '1.0.0', ['a.php' => '<?php'], [], null, $ekstra);
            mkdir("$m/ut");
            Pakkeleser::pakkUt($this->pakke(), "$m/$tag.zip", $tag, '1.0.0', "$m/ut");
            self::fail('Pakken skulle vært avvist.');
        } catch (BivrostFeil $e) {
            self::assertSame($feil, $e->tekstnokkel);
        } finally {
            Mappe::slett($m);
        }
    }

    public function testVersjonsfilenMaaSvareTilTaggen(): void
    {
        $m = Mappe::ny();
        try {
            $tag = PakkeBygger::lag($m, 'testapp', 'testapp-v', 'testapp/', '1.0.0', ['a.php' => '<?php']);
            mkdir("$m/ut");
            $this->expectException(BivrostFeil::class);
            Pakkeleser::pakkUt($this->pakke(), "$m/$tag.zip", 'testapp-v1.0.1', '1.0.1', "$m/ut");
        } finally {
            Mappe::slett($m);
        }
    }

    /** @return iterable<string, array{string, mixed, bool}> */
    public static function mellomstopp(): iterable
    {
        yield 'uten krav' => ['0.1.0', null, true];
        yield 'over grensen' => ['1.5.0', '>=1.4.0', true];
        yield 'på grensen' => ['1.4.0', '>=1.4.0', true];
        yield 'under grensen' => ['1.3.9', '>=1.4.0', false];
        yield 'forhåndsversjon av stoppet er under' => ['1.4.0-rc.1', '>=1.4.0', false];
        yield 'forhåndsversjon som Composer ikke leser, over grensen' => ['2.0.0-pre.1', '>=1.4.0', true];
        yield 'annen form enn nedre grense' => ['1.5.0', '^1.4', false];
        yield 'øvre grense er ikke lov' => ['1.5.0', '>=1.4.0 <2.0.0', false];
        yield 'ikke en tekst' => ['1.5.0', ['>=1.4.0'], false];
        yield 'forhåndsversjon -pre' => ['1.4.0-pre.1', '>=1.4.0', false];
        yield 'forhåndsversjon -p1 (version_compare regner den som høyere)' => ['1.4.0-p1', '>=1.4.0', false];
        yield 'forhåndsversjon -patch' => ['1.4.0-patch.1', '>=1.4.0', false];
        yield 'forhåndsversjon -1' => ['1.4.0-1', '>=1.4.0', false];
        yield 'forhåndsversjon av en høyere versjon' => ['1.5.0-rc.1', '>=1.4.0', true];
        yield 'installert uten X.Y.Z' => ['1.4', '>=1.4.0', false];
    }

    #[DataProvider('mellomstopp')]
    public function testMellomstoppErBareEnNedreGrense(string $installert, mixed $krav, bool $kan): void
    {
        self::assertSame($kan, Pakke::kanOppdateresFra($installert, $krav));
    }

    public function testSymbolskLenkeAvvises(): void
    {
        $m = Mappe::ny();
        try {
            $tag = PakkeBygger::lag($m, 'testapp', 'testapp-v', 'testapp/', '1.0.0', ['a.php' => '<?php']);
            $z = new \ZipArchive();
            $z->open("$m/$tag.zip");
            $z->addFromString('testapp/lenke', '/etc/passwd');
            $z->setExternalAttributesName('testapp/lenke', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
            $z->close();
            mkdir("$m/ut");
            $this->expectException(BivrostFeil::class);
            Pakkeleser::pakkUt($this->pakke(), "$m/$tag.zip", $tag, '1.0.0', "$m/ut");
        } finally {
            Mappe::slett($m);
        }
    }
}
