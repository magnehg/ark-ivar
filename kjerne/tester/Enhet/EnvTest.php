<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testTolkerVerdierKommentarerOgAnforselstegn(): void
    {
        $e = Env::tolk("\xEF\xBB\xBF# kommentar\nA=1\nB = to  # etter\nC=\"med \\\"sitat\\\" og # tegn\"\n\nD=\n");
        self::assertSame(['A' => '1', 'B' => 'to', 'C' => 'med "sitat" og # tegn', 'D' => ''], $e);
    }

    public function testUgyldigLinjeGirFeil(): void
    {
        $this->expectException(BivrostFeil::class);
        Env::tolk("smaa=1\n");
    }

    public function testUavsluttetSitatGirFeil(): void
    {
        $this->expectException(BivrostFeil::class);
        Env::tolk("A=\"uten slutt\n");
    }

    public function testSkriverAtomiskMed0640OgLeserTilbake(): void
    {
        $m = Mappe::ny();
        try {
            $fil = "$m/.env";
            Env::skrivFil($fil, ['DB_PASSORD' => 'a"b\\c', 'X' => 'y'], "Hemmeligheter\nikke i git");
            self::assertSame(0640, fileperms($fil) & 0777);
            $e = Env::fraFil($fil);
            self::assertSame('a"b\\c', $e->hent('DB_PASSORD'));
            self::assertSame('y', $e->krev('X'));
            $this->expectException(BivrostFeil::class);
            Env::skrivFil($fil, ['A' => 'b']);
        } finally {
            Mappe::slett($m);
        }
    }

    public function testNektRegnerIkkeKontrolltegn(): void
    {
        self::assertSame('kontrolltegn', Env::verdiFeil("a\nb"));
        self::assertNull(Env::verdiFeil("a\tb æøå"));
        $this->expectException(BivrostFeil::class);
        Env::fraListe(['A' => "x\ny"]);
    }

    public function testSannOgHeltallOgKrev(): void
    {
        $e = Env::fraListe(['A' => 'ja', 'B' => '0', 'C' => '42', 'D' => 'x']);
        self::assertTrue($e->sann('A'));
        self::assertFalse($e->sann('B', true));
        self::assertTrue($e->sann('MANGLER', true));
        self::assertSame(42, $e->heltall('C', 1));
        self::assertSame(1, $e->heltall('D', 1));
        $this->expectException(BivrostFeil::class);
        $e->krev('MANGLER');
    }
}
