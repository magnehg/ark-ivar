<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Integrasjon;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Innlogging\Forsok;
use Bivrost\Kjerne\Innlogging\LokalBrukere;
use Bivrost\Kjerne\Innlogging\LokalInnlogging;
use Bivrost\Kjerne\Innlogging\Passordhash;
use Bivrost\Kjerne\Innlogging\Totp;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Kontekst;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Logg\Aktor;
use Bivrost\Kjerne\Logg\DbLoggLager;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Okt\Innlogget;
use Bivrost\Kjerne\Okt\Okt;
use Bivrost\Kjerne\Sprak\Klartekst;
use Bivrost\Kjerne\Sprak\Tekster;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tilgang\Urn;

final class OktOgInnloggingTest extends DbTestCase
{
    private FastKlokke $klokke;
    private string $rot = '';
    private Logg $logg;
    private LokalBrukere $brukere;
    private LokalInnlogging $inn;
    private Totp $totp;
    private string $hemmelighet = '';
    private string $id = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->kjerneskjema();
        $this->klokke = new FastKlokke();
        $this->rot = Mappe::ny();
        $app = AppKonfig::fraListe(['app' => 'gerd', 'appnavn' => 'Gerð', 'rot' => $this->rot]);
        $this->logg = new Logg(new DbLoggLager($this->db), new Klartekst(new Tekster(), 'nb'), new Kontekst(Aktor::system()), $app, $this->klokke);
        $this->brukere = new LokalBrukere($this->db, Hemmelighetsboks::fraBase64(Hemmelighetsboks::nyNokkel()));
        $this->totp = new Totp($this->klokke);
        $this->inn = new LokalInnlogging($this->brukere, new Forsok($this->db, $this->klokke, 'hmac'), $this->totp, $this->logg, $this->klokke, static function (): void {
        });
        $this->hemmelighet = $this->totp->nyHemmelighet();
        $this->id = $this->brukere->opprett('drift', 'Drift Driftesen', 'Driftsansvarlig', null, Passordhash::hash('lang og god passfrase'), $this->hemmelighet, 0);
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->rot);
        parent::tearDown();
    }

    private function okt(): Okt
    {
        return new Okt($this->db, $this->klokke, 1800, 12 * 3600, true);
    }

    /** Sender kapselen fra et svar tilbake i neste forespørsel. */
    /** @return array<string, string> */
    private static function kapsel(Svar $s): array
    {
        $k = [];
        foreach ($s->kapsler() as $c) {
            [$nv] = explode(';', $c, 2);
            [$n, $v] = explode('=', $nv, 2);
            $k[$n] = $v;
        }

        return $k;
    }

    public function testOktLagresRoteresOgUtloperVedInaktivitetOgMaksLevetid(): void
    {
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $o->loggInn(new Innlogget(Aktor::bruker('sub-1', 'Kari'), [Urn::DRIFT], 'oidc', 0, 'sid-1'));
        $s = new Svar();
        $o->lagre($s);
        $kapsel = self::kapsel($s);
        self::assertArrayHasKey('__Host-bivrost', $kapsel);
        self::assertStringContainsString('Secure', $s->kapsler()[0]);
        self::assertSame(64, strlen(Felt::tekst($this->db->verdi('SELECT id_hash FROM bivrost_okt'))), 'Bare hashen av ID-en lagres.');
        self::assertNull($this->db->verdi('SELECT id_hash FROM bivrost_okt WHERE id_hash = ?', [$kapsel['__Host-bivrost']]));

        $o2 = $this->okt();
        $o2->start(new Foresporsel('GET', '/', kapsler: $kapsel));
        self::assertSame('Kari', $o2->innlogget()?->aktor->navn);
        $o2->roter();
        $s2 = new Svar();
        $o2->lagre($s2);
        $ny = self::kapsel($s2);
        self::assertNotSame($kapsel['__Host-bivrost'], $ny['__Host-bivrost']);
        $gammel = $this->okt();
        $gammel->start(new Foresporsel('GET', '/', kapsler: $kapsel));
        self::assertNull($gammel->innlogget(), 'Den gamle ID-en virker ikke etter rotasjon.');

        $this->klokke->frem(1801);
        $o3 = $this->okt();
        $o3->start(new Foresporsel('GET', '/', kapsler: $ny));
        self::assertNull($o3->innlogget(), 'Tidsavbrudd ved inaktivitet.');

        $o4 = $this->okt();
        $o4->start(new Foresporsel('GET', '/'));
        $o4->loggInn(new Innlogget(Aktor::bruker('sub-1', 'Kari'), [], 'oidc', 0, 'sid-2'));
        $s4 = new Svar();
        $o4->lagre($s4);
        $k4 = self::kapsel($s4);
        for ($i = 0; $i < 27; $i++) {
            $this->klokke->frem(1700);
            $x = $this->okt();
            $x->start(new Foresporsel('GET', '/', kapsler: $k4));
            $x->lagre(new Svar());
        }
        $x = $this->okt();
        $x->start(new Foresporsel('GET', '/', kapsler: $k4));
        self::assertNull($x->innlogget(), 'Maksimal levetid (12 timer) selv med aktivitet.');
    }

    public function testBackchannelAvslutterOktenForSid(): void
    {
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $o->loggInn(new Innlogget(Aktor::bruker('sub-1', 'Kari'), [], 'oidc', 0, 'sid-9'));
        $s = new Svar();
        $o->lagre($s);
        self::assertSame(1, $this->okt()->avsluttOidc('sid-9', 'sub-1'));
        $x = $this->okt();
        $x->start(new Foresporsel('GET', '/', kapsler: self::kapsel($s)));
        self::assertNull($x->innlogget());
    }

    public function testNodinnloggingMedPassordOgTotpGirBareDrift(): void
    {
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o, 'Drift', 'lang og god passfrase', '10.0.0.1');
        self::assertNull($o->innlogget(), 'Passordet alene gir ingen tilgang.');
        $i = $this->inn->totp($o, $this->totp->kode($this->hemmelighet), '10.0.0.1');
        self::assertSame([Urn::DRIFT], $i->urner);
        self::assertSame('lokal', $i->aktor->type);
        self::assertSame('Drift Driftesen', $i->aktor->navn);
        $h = $this->logg->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:innlogging:lykkes'));
        self::assertSame('Drift Driftesen', $h[0]['hendelse']->hvem['navn'], 'Loggen har navnet på den som logget inn.');
    }

    public function testTotpKanIkkeGjenbrukes(): void
    {
        $kode = $this->totp->kode($this->hemmelighet);
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o, 'drift', 'lang og god passfrase', '10.0.0.1');
        $this->inn->totp($o, $kode, '10.0.0.1');
        $o2 = $this->okt();
        $o2->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o2, 'drift', 'lang og god passfrase', '10.0.0.1');
        $this->expectException(HttpFeil::class);
        $this->inn->totp($o2, $kode, '10.0.0.1');
    }

    public function testSammeSvarForUkjentBrukerOgFeilPassord(): void
    {
        foreach ([['ukjent', 'lang og god passfrase'], ['drift', 'feil passord her']] as [$b, $p]) {
            try {
                $this->inn->passord($this->okt(), $b, $p, '10.0.0.2');
                self::fail('Skulle avvises.');
            } catch (HttpFeil $e) {
                self::assertSame([401, 'innlogging_feil', 'bivrost.feil.innlogging.feil'], [$e->status, $e->kode, $e->tekstnokkel]);
            }
        }
    }

    public function testGrensenKanIkkeOmgasMedVarianterAvBrukernavnet(): void
    {
        // Fullbredde, store bokstaver, mellomrom og nullbreddetegn: alle blir «drift» eller avvises, og telles på samme bruker.
        $varianter = ['ｄｒｉｆｔ', 'DRIFT', ' drift ', 'Drift', "dr\u{200B}ift"];
        $sperret = false;
        foreach (array_merge($varianter, $varianter) as $v) {
            try {
                $this->inn->passord($this->okt(), $v, 'feil passord her', '10.0.0.' . random_int(10, 250));
            } catch (HttpFeil $e) {
                if ($e->status === 429) {
                    $sperret = true;
                    break;
                }
            }
        }
        self::assertTrue($sperret, 'Grensen per brukernavn/bruker-ID skal slå til.');
        self::assertNull(LokalBrukere::normaliser("dr\u{200B}ift"), 'Nullbreddetegn passer ikke mønsteret.');
        self::assertSame('drift', LokalBrukere::normaliser('ｄｒｉｆｔ'));
        $this->expectException(HttpFeil::class);
        $this->inn->passord($this->okt(), 'drift', 'lang og god passfrase', '10.0.0.3');
    }

    public function testGjenopprettingskodeVirkerEnGang(): void
    {
        $koder = $this->brukere->nyeGjenopprettingskoder($this->id);
        self::assertCount(10, $koder);
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o, 'drift', 'lang og god passfrase', '10.0.0.1');
        $this->inn->totp($o, strtolower($koder[0]), '10.0.0.1');
        self::assertSame(9, $this->brukere->gjenstaendeKoder($this->id));
        $o2 = $this->okt();
        $o2->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o2, 'drift', 'lang og god passfrase', '10.0.0.1');
        $this->expectException(HttpFeil::class);
        $this->inn->totp($o2, $koder[0], '10.0.0.1');
    }

    public function testPassordbytteKreverDetGamleOgAvslutterAndreOkter(): void
    {
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o, 'drift', 'lang og god passfrase', '10.0.0.1');
        $i = $this->inn->totp($o, $this->totp->kode($this->hemmelighet), '10.0.0.1');
        $annen = $this->okt();
        $annen->start(new Foresporsel('GET', '/'));
        $annen->loggInn($i);
        $annen->lagre(new Svar());
        try {
            $this->inn->byttPassord($o, $i, 'feil gammelt passord', 'ny og enda bedre frase', '10.0.0.1');
            self::fail('Det gamle passordet kreves.');
        } catch (HttpFeil $e) {
            self::assertSame('passord_feil', $e->kode);
        }
        try {
            $this->inn->byttPassord($o, $i, 'lang og god passfrase', 'passord123456', '10.0.0.1');
            self::fail('Et vanlig passord avvises.');
        } catch (HttpFeil $e) {
            self::assertSame('passord_svakt', $e->kode);
        }
        $this->inn->byttPassord($o, $i, 'lang og god passfrase', 'ny og enda bedre frase', '10.0.0.1');
        self::assertSame(0, Felt::tall($this->db->verdi("SELECT COUNT(*) FROM bivrost_okt WHERE aktor_type = 'lokal'")));
        $b = $this->brukere->hent($this->id);
        self::assertSame(2, Felt::tall($b['generasjon'] ?? 0));
    }

    public function testNyTotpKreverPassordOgKodeFraDenNyeOgAvslutterAndreOkter(): void
    {
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o, 'drift', 'lang og god passfrase', '10.0.0.1');
        $i = $this->inn->totp($o, $this->totp->kode($this->hemmelighet), '10.0.0.1');
        $annen = $this->okt();
        $annen->start(new Foresporsel('GET', '/'));
        $annen->loggInn($i);
        $annen->lagre(new Svar());
        try {
            $this->inn->bekreftTotp($o, $i, '123456', '10.0.0.1');
            self::fail('Uten steg 1 finnes ingen ny TOTP å bekrefte.');
        } catch (HttpFeil $e) {
            self::assertSame([409, 'totp_ikke_startet'], [$e->status, $e->kode]);
        }
        try {
            $this->inn->nyTotp($o, $i, 'feil passord her', 'Gerð', '10.0.0.1');
            self::fail('Passordet kreves.');
        } catch (HttpFeil $e) {
            self::assertSame('passord_feil', $e->kode);
        }
        $ny = $this->inn->nyTotp($o, $i, 'lang og god passfrase', 'Gerð', '10.0.0.1');
        self::assertNotSame($this->hemmelighet, $ny['hemmelighet']);
        self::assertStringNotContainsString($ny['hemmelighet'], (string) json_encode($o->hent('lokal_ny_totp')), 'Den ventende hemmeligheten er kryptert i økten.');
        self::assertSame($this->hemmelighet, $this->brukere->totpHemmelighet((array) $this->brukere->hent($this->id)), 'Den gamle virker til den nye er bekreftet.');
        $this->klokke->frem(30);
        try {
            $this->inn->bekreftTotp($o, $i, $this->totp->kode($this->hemmelighet), '10.0.0.1');
            self::fail('En kode fra den gamle hemmeligheten bekrefter ikke den nye.');
        } catch (HttpFeil $e) {
            self::assertSame([422, 'totp'], [$e->status, $e->kode]);
        }
        $koder = $this->inn->bekreftTotp($o, $i, $this->totp->kode($ny['hemmelighet']), '10.0.0.1');
        self::assertCount(10, $koder);
        $b = (array) $this->brukere->hent($this->id);
        self::assertSame($ny['hemmelighet'], $this->brukere->totpHemmelighet($b));
        self::assertSame(2, Felt::tall($b['generasjon']), 'Generasjonen øker, så andre økter slutter å gjelde.');
        self::assertSame(0, Felt::tall($this->db->verdi("SELECT COUNT(*) FROM bivrost_okt WHERE aktor_type = 'lokal'")));
        self::assertNotNull($o->innlogget());
        self::assertSame(2, $o->innlogget()->generasjon);

        // Bekreftelseskoden kan ikke brukes til en ny innlogging (tidssteget er lagret).
        $o2 = $this->okt();
        $o2->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o2, 'drift', 'lang og god passfrase', '10.0.0.1');
        $this->expectException(HttpFeil::class);
        $this->inn->totp($o2, $this->totp->kode($ny['hemmelighet']), '10.0.0.1');
    }

    public function testNyTotpUtloperEtterFemMinutter(): void
    {
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $this->inn->passord($o, 'drift', 'lang og god passfrase', '10.0.0.1');
        $i = $this->inn->totp($o, $this->totp->kode($this->hemmelighet), '10.0.0.1');
        $ny = $this->inn->nyTotp($o, $i, 'lang og god passfrase', 'Gerð', '10.0.0.1');
        $this->klokke->frem(301);
        try {
            $this->inn->bekreftTotp($o, $i, $this->totp->kode($ny['hemmelighet']), '10.0.0.1');
            self::fail('Den ventende hemmeligheten er utløpt.');
        } catch (HttpFeil $e) {
            self::assertSame('totp_ikke_startet', $e->kode);
        }
        self::assertSame($this->hemmelighet, $this->brukere->totpHemmelighet((array) $this->brukere->hent($this->id)));
    }

    public function testByttetKrypteringsnokkelGirTotpUlesbarOgGjenopprettingskodenVirker(): void
    {
        $koder = $this->brukere->nyeGjenopprettingskoder($this->id);
        $brukere = new LokalBrukere($this->db, Hemmelighetsboks::fraBase64(Hemmelighetsboks::nyNokkel()));
        $inn = new LokalInnlogging($brukere, new Forsok($this->db, $this->klokke, 'hmac'), $this->totp, $this->logg, $this->klokke, static function (): void {
        });
        self::assertFalse($brukere->totpLesbar((array) $brukere->hent($this->id)));
        $o = $this->okt();
        $o->start(new Foresporsel('GET', '/'));
        $inn->passord($o, 'drift', 'lang og god passfrase', '10.0.0.1');
        try {
            $inn->totp($o, $this->totp->kode($this->hemmelighet), '10.0.0.1');
            self::fail('TOTP kan ikke leses med en annen nøkkel.');
        } catch (HttpFeil $e) {
            self::assertSame([401, 'totp_ulesbar', 'bivrost.feil.innlogging.totp_ulesbar'], [$e->status, $e->kode, $e->tekstnokkel]);
        }
        $i = $inn->totp($o, $koder[0], '10.0.0.1');
        self::assertSame([Urn::DRIFT], $i->urner);
        $h = $this->logg->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:innlogging:lykkes'));
        self::assertFalse($h[0]['hendelse']->data['totp_lesbar'] ?? null);
    }
}
