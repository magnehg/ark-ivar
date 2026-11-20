<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Oppdatering\Pakke;
use Bivrost\Kjerne\Oppdatering\Signatur;
use Bivrost\Kjerne\Oppdatering\Utgivernokler;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use PHPUnit\Framework\TestCase;

/** Nøkkelfilene og fingeravtrykket for den klarerte utgivernøkkelen (BIV-DRF-14). */
final class UtgivernoklerTest extends TestCase
{
    private string $m = '';

    protected function setUp(): void
    {
        $this->m = Mappe::ny();
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->m);
    }

    public function testNormaliserGodtarBareEd25519Nokler(): void
    {
        $n = Signatur::nyttNokkelpar()['offentlig'];
        self::assertSame($n, Utgivernokler::normaliser("  $n\r\n"));
        self::assertNull(Utgivernokler::normaliser(''));
        self::assertNull(Utgivernokler::normaliser(base64_encode(str_repeat('x', 31))));
        self::assertNull(Utgivernokler::normaliser(Signatur::nyttNokkelpar()['hemmelig']), 'En hemmelig nøkkel (64 byte) er ikke en utgivernøkkel.');
        self::assertNull(Utgivernokler::normaliser('ikke base64!'));
    }

    public function testNokkelfilenLesesOgEnOdelagtFilErEnFeil(): void
    {
        $n = Signatur::nyttNokkelpar()['offentlig'];
        self::assertNull(Utgivernokler::lesFil("$this->m/finnes-ikke", 'bivrost-kjerne', 'finnes-ikke'));
        file_put_contents("$this->m/god", "$n\n");
        self::assertSame($n, Utgivernokler::lesFil("$this->m/god", 'bivrost-kjerne', 'god'));
        foreach (['tom' => '', 'feil' => 'SOjNW2FSyLjhWZF6', 'stor' => str_repeat('A', 5000)] as $navn => $innhold) {
            file_put_contents("$this->m/$navn", $innhold);
            try {
                Utgivernokler::lesFil("$this->m/$navn", 'gerd', "gerd-v1.zip:gerd/$navn");
                self::fail("$navn skal avvises.");
            } catch (BivrostFeil $e) {
                self::assertSame('bivrost.feil.oppdatering.nokkelfil', $e->tekstnokkel);
                self::assertSame(['pakke' => 'gerd', 'fil' => "gerd-v1.zip:gerd/$navn"], $e->verdier, 'Pakken og stien står i feilen.');
            }
        }
    }

    public function testNokkelfilenIEnZipLesesUtenAaPakkeUt(): void
    {
        $p = new Pakke('bivrost-kjerne', 'Bivrost Kjerne', 'test/bivrost', 'bivrost-kjerne-v', 'bivrost/kjerne/');
        self::assertSame('bivrost/kjerne/signeringsnokkel.offentlig', $p->nokkelfil());
        $n = Signatur::nyttNokkelpar()['offentlig'];
        $z = new \ZipArchive();
        $z->open("$this->m/a.zip", \ZipArchive::CREATE);
        $z->addFromString($p->nokkelfil(), "$n\n");
        $z->close();
        self::assertSame($n, Utgivernokler::iZip("$this->m/a.zip", $p));
        $z = new \ZipArchive();
        $z->open("$this->m/b.zip", \ZipArchive::CREATE);
        $z->addFromString($p->nokkelfil(), str_repeat('A', 4096));
        $z->addFromString('bivrost/kjerne/x.php', '<?php');
        $z->close();
        self::assertNull(Utgivernokler::iZip("$this->m/b.zip", $p), 'For stor.');
        self::assertNull(Utgivernokler::iZip("$this->m/finnes-ikke.zip", $p));
    }

    public function testFingeravtrykkSomOpenSsh(): void
    {
        $n = 'SOjNW2FSyLjhWZF6M8Aa09AmsqNpGGJOF1KxHoZDOKY=';
        $ventet = 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($n, true), true)), '=');
        self::assertSame($ventet, Utgivernokler::fingeravtrykk($n));
        self::assertMatchesRegularExpression('#^SHA256:[A-Za-z0-9+/]{43}$#D', Utgivernokler::fingeravtrykk($n));
    }

    public function testUtgivernokkelenIRepoetErGyldig(): void
    {
        $fil = __DIR__ . '/../../signeringsnokkel.offentlig';
        self::assertNotNull(Utgivernokler::lesFil($fil, 'bivrost-kjerne', 'kjerne/signeringsnokkel.offentlig'), 'kjerne/signeringsnokkel.offentlig følger med pakken og klareres av installasjonene.');
    }
}
