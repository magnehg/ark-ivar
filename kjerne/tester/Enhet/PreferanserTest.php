<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Preferanser\Preferanser;
use PHPUnit\Framework\TestCase;

final class PreferanserTest extends TestCase
{
    public function testRekkefolgenAppOverFellesOverSystem(): void
    {
        $felles = Preferanser::fraListe(['tema' => 'vinrod', 'modus' => 'mork', 'preferredLanguage' => 'nn']);
        $app = Preferanser::fraListe(['modus' => 'lys']);
        $eff = $app->over($felles->over(Preferanser::standard()));
        self::assertSame('vinrod', $eff->hent('tema'));
        self::assertSame('lys', $eff->hent('modus'));
        self::assertSame('nn', $eff->hent('preferredLanguage'));
        self::assertSame('begrenset', $eff->hent('bredde'));
        self::assertSame('Europe/Oslo', $eff->hent('timezone'));
    }

    public function testStrengKontroll(): void
    {
        foreach ([['tema' => 'rosa'], ['ukjent' => 'x'], ['timezone' => 'Mars/Olympus'], ['locale' => 'nb_NO'], ['tema' => ['marine']]] as $d) {
            try {
                Preferanser::fraListe($d);
                self::fail((string) json_encode($d));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], Preferanser::fraListe(['tema' => 'rosa', 'ukjent' => 1], false)->tilListe(), 'Data fra Sentral overses når de er ugyldige.');
    }

    public function testScimTilordning(): void
    {
        $p = Preferanser::fraListe(['tema' => 'mose', 'hoyKontrast' => 'ja', 'preferredLanguage' => 'en', 'locale' => 'en-GB', 'timezone' => 'UTC']);
        $s = $p->scim();
        self::assertSame('en', $s['preferredLanguage']);
        self::assertSame(['tema' => 'mose', 'hoyKontrast' => 'ja'], $s[Preferanser::SCIM_UTVIDELSE]);
        self::assertEquals($p->tilListe(), Preferanser::fraScim($s)->tilListe());
        self::assertSame(['tema' => 'mose'], $p->med(['hoyKontrast' => null, 'preferredLanguage' => null, 'locale' => null, 'timezone' => null])->tilListe());
    }
}
