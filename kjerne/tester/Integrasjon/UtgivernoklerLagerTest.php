<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Integrasjon;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Oppdatering\Signatur;
use Bivrost\Kjerne\Oppdatering\Utgivernokler;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;

/** Den klarerte utgivernøkkelen i databasen (bivrost_innstilling), én per pakke (BIV-DRF-14). */
final class UtgivernoklerLagerTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->kjerneskjema();
    }

    public function testLagresPerPakkeOgKanByttes(): void
    {
        $u = new Utgivernokler($this->db, new FastKlokke());
        self::assertNull($u->hent('bivrost-kjerne'));
        $a = Signatur::nyttNokkelpar()['offentlig'];
        $b = Signatur::nyttNokkelpar()['offentlig'];
        $u->lagre('bivrost-kjerne', " $a\n", 'installasjon', '0.1.0');
        $k = $u->hent('bivrost-kjerne');
        self::assertIsArray($k);
        self::assertSame($a, $k['nokkel'], 'Lagret i kanonisk form.');
        self::assertSame('installasjon', $k['kilde']);
        self::assertSame('0.1.0', $k['versjon']);
        self::assertSame('2026-11-13T10:00:00Z', $k['klarert']);
        self::assertSame(Utgivernokler::fingeravtrykk($a), $k['fingeravtrykk']);
        self::assertNull($u->hent('gerd'), 'Hver pakke har sin egen nøkkel.');
        $u->lagre('bivrost-kjerne', $b, 'bytte', '0.2.0');
        self::assertSame($b, $u->hent('bivrost-kjerne')['nokkel'] ?? null);
        self::assertSame(1, Felt::tall($this->db->verdi("SELECT COUNT(*) FROM bivrost_innstilling WHERE nokkel LIKE 'oppdatering.utgivernokkel.%'")));
    }

    /** Tilbakerullingen beholder nøklene slik de er (BIV-B-034: et bytte går bare fremover). */
    public function testTaVareOgLeggTilbakeBeholderNoklene(): void
    {
        $u = new Utgivernokler($this->db, new FastKlokke());
        $a = Signatur::nyttNokkelpar()['offentlig'];
        $b = Signatur::nyttNokkelpar()['offentlig'];
        $c = Signatur::nyttNokkelpar()['offentlig'];
        $u->lagre('bivrost-kjerne', $b, 'bytte', '0.2.0');
        $this->db->utfor("INSERT INTO bivrost_innstilling (nokkel, verdi, endret) VALUES ('annen.innstilling', 'x', NOW(6))");
        $rader = $u->taVare();
        self::assertCount(1, $rader, 'Bare utgivernøklene.');

        // Som etter innlesingen av en eldre databasekopi: den forrige nøkkelen og en nøkkel for en annen pakke.
        $u->lagre('bivrost-kjerne', $a, 'installasjon', '0.1.0');
        $u->lagre('gerd', $c, 'installasjon', '0.1.0');
        $u->leggTilbake($rader);
        $k = $u->hent('bivrost-kjerne');
        self::assertIsArray($k);
        self::assertSame($b, $k['nokkel']);
        self::assertSame('bytte', $k['kilde']);
        self::assertNull($u->hent('gerd'), 'En nøkkel som ikke fantes før tilbakerullingen, fjernes.');
        self::assertSame('x', $this->db->verdi("SELECT verdi FROM bivrost_innstilling WHERE nokkel = 'annen.innstilling'"), 'Andre innstillinger røres ikke.');

        // Fantes ingen nøkkel, fantes heller ingen etterpå.
        $u->leggTilbake([]);
        self::assertNull($u->hent('bivrost-kjerne'));
    }

    public function testUgyldigNokkelEllerKildeLagresIkke(): void
    {
        $u = new Utgivernokler($this->db);
        $this->expectException(\InvalidArgumentException::class);
        $u->lagre('bivrost-kjerne', Signatur::nyttNokkelpar()['offentlig'], 'tilfeldig', null);
    }

    public function testEnOdelagtRadGirFeilAldriIngenNokkel(): void
    {
        $u = new Utgivernokler($this->db);
        foreach (['ikke json', '{"nokkel":"kort","kilde":"installasjon"}', '{"nokkel":"' . Signatur::nyttNokkelpar()['offentlig'] . '","kilde":"ukjent"}'] as $verdi) {
            $this->db->utfor(
                'INSERT INTO bivrost_innstilling (nokkel, verdi, endret) VALUES (?, ?, NOW(6)) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi)',
                ['oppdatering.utgivernokkel.bivrost-kjerne', $verdi]
            );
            try {
                $u->hent('bivrost-kjerne');
                self::fail("Skal avvises: $verdi");
            } catch (BivrostFeil $e) {
                self::assertSame('bivrost.feil.oppdatering.utgivernokkel_lager', $e->tekstnokkel);
            }
        }
    }
}
