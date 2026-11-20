<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Integrasjon;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Kontekst;
use Bivrost\Kjerne\Logg\Aktor;
use Bivrost\Kjerne\Logg\DbLoggLager;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Logg\Verdi;
use Bivrost\Kjerne\Okt\Innlogget;
use Bivrost\Kjerne\Sprak\Klartekst;
use Bivrost\Kjerne\Sprak\Tekster;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\Skjema;
use Bivrost\Kjerne\Tilgang\Rbac;
use Bivrost\Kjerne\Tilgang\Rolleregister;
use Bivrost\Kjerne\Tilgang\Urn;

final class LoggOgRollerTest extends DbTestCase
{
    private string $rot = '';
    private FastKlokke $klokke;
    private Kontekst $kontekst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kjerneskjema();
        $this->rot = Mappe::ny();
        $this->klokke = new FastKlokke();
        $this->kontekst = new Kontekst(Aktor::bruker('sub-kari', 'Kari Nordmann', 'Arkivleder'));
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->rot);
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $roller
     * @param array<string, mixed> $ekstra
     * @return array{0: AppKonfig, 1: Rolleregister, 2: Logg}
     */
    private function oppsett(array $roller, array $ekstra = []): array
    {
        $app = AppKonfig::fraListe(['app' => 'gerd', 'appnavn' => 'Gerð', 'rot' => $this->rot, 'roller' => $roller] + $ekstra);
        $reg = new Rolleregister($this->db, $app, new Tekster());
        $logg = new Logg(new DbLoggLager($this->db), new Klartekst(new Tekster(), 'nb', $reg), $this->kontekst, $app, $this->klokke);

        return [$app, $reg, $logg];
    }

    public function testFellesFormatMedKlartekstOgSkjulteHemmeligheter(): void
    {
        [, , $logg] = $this->oppsett([]);
        $h = $logg->skriv(
            'oppdatering:filer',
            new LoggObjekt(null, 'pakke', 'Pakke', 'gerd', 'Gerð'),
            ['versjon' => Verdi::tekst('1.0.0')],
            ['versjon' => Verdi::tekst('1.1.0')],
            ['token' => 'hemmelig', 'nestet' => ['passord' => 'x', 'ok' => 1], 'nokkel_navn' => 'GitHub Actions']
        );
        $l = $h->tilListe();
        self::assertSame(['urn' => 'urn:bivrost:hendelse:oppdatering:filer', 'tekst' => 'Nye filer lagt inn'], $l['hva']);
        self::assertSame(['type' => 'bruker', 'type_tekst' => 'Bruker', 'id' => 'sub-kari', 'navn' => 'Kari Nordmann', 'tittel' => 'Arkivleder'], $l['hvem']);
        self::assertSame(['kode' => 'nett', 'tekst' => 'Nettleser'], $l['kilde']);
        self::assertSame([['id' => 'gerd', 'navn' => 'Gerð']], $l['apper']);
        self::assertSame(['token' => '[skjult]', 'nestet' => ['passord' => '[skjult]', 'ok' => 1], 'nokkel_navn' => 'GitHub Actions'], $l['data']);
        self::assertSame([], Skjema::feil('logghendelse', $l), 'Hendelsen følger skjema/logghendelse.schema.json.');
        $lest = $logg->lager()->les(new LoggFilter(app: 'gerd'));
        self::assertCount(1, $lest);
        self::assertEquals($h, $lest[0]['hendelse']);
        self::assertCount(0, $logg->lager()->les(new LoggFilter(app: 'hugr')));
        self::assertCount(1, $logg->lager()->les(new LoggFilter(bruker: 'sub-kari', korrelasjon: $this->kontekst->korrelasjon(), type: 'urn:bivrost:hendelse:oppdatering')));
    }

    public function testBareSikkerhetsloggenRyddes(): void
    {
        [, , $logg] = $this->oppsett([]);
        $this->klokke->sett('2025-01-01 00:00:00');
        $logg->skriv('innlogging:avvist', null, [], [], [], 'sikkerhet');
        $logg->skriv('rolleregister:ny');
        $this->klokke->sett('2026-11-13 10:00:00');
        $logg->skriv('innlogging:avvist', null, [], [], [], 'sikkerhet');
        self::assertSame(1, $logg->lager()->ryddSikkerhetslogg(365));
        $igjen = array_map(static fn (array $r): string => $r['hendelse']->hva['urn'] . '@' . substr($r['hendelse']->tid, 0, 4), $logg->lager()->les(new LoggFilter()));
        self::assertSame(['urn:bivrost:hendelse:innlogging:avvist@2026', 'urn:bivrost:hendelse:rolleregister:ny@2025'], $igjen);
        $n = 0;
        $logg->lager()->hverEn(new LoggFilter(fra: '2025-01-01', til: '2025-01-01'), function () use (&$n): void {
            $n++;
        });
        self::assertSame(1, $n);
    }

    public function testRolleregisterNyEndretDeaktivertOgTilbake(): void
    {
        [, $reg, $logg] = $this->oppsett([['navn' => 'arkivar', 'tekst' => 'Arkivar'], ['navn' => 'leser', 'tekst' => 'Leser']]);
        $e = $reg->synk($logg);
        self::assertCount(6, $e, 'drift, brukeradministrator, appadministrator, app-brukeradministrator, arkivar og leser');
        self::assertSame([], $reg->synk($logg), 'Uendret definisjon skriver ingenting.');
        self::assertSame('Arkivar (Gerð)', $reg->navn('urn:bivrost:app:gerd:rolle:arkivar', 'nb'));
        self::assertSame('App administrator in Gerð', $reg->navn('urn:bivrost:app:gerd:tilgang:appadministrator', 'en'));

        [, $reg2, $logg2] = $this->oppsett([['navn' => 'arkivar', 'tekst' => ['nb' => 'Arkivar', 'en' => 'Archivist']]]);
        $e = $reg2->synk($logg2);
        self::assertEqualsCanonicalizing([
            ['urn' => 'urn:bivrost:app:gerd:rolle:arkivar', 'endring' => 'endret'],
            ['urn' => 'urn:bivrost:app:gerd:rolle:leser', 'endring' => 'deaktivert'],
        ], $e);
        self::assertFalse($reg2->aktiv('urn:bivrost:app:gerd:rolle:leser'));
        self::assertSame('Leser (Gerð)', $reg2->navn('urn:bivrost:app:gerd:rolle:leser', 'nb'), 'Navnet på en deaktivert rolle finnes fortsatt.');
        $leser = array_values(array_filter($reg2->alle(), static fn (array $r): bool => $r['navn'] === 'leser'));
        self::assertFalse($leser[0]['aktiv']);

        [, $reg3, $logg3] = $this->oppsett([['navn' => 'arkivar', 'tekst' => ['nb' => 'Arkivar', 'en' => 'Archivist']], ['navn' => 'leser', 'tekst' => 'Leser']]);
        self::assertSame([['urn' => 'urn:bivrost:app:gerd:rolle:leser', 'endring' => 'gjenopprettet']], $reg3->synk($logg3));
        $typer = array_map(static fn (array $r): string => $r['hendelse']->hva['urn'], $logg3->lager()->les(new LoggFilter(type: 'urn:bivrost:hendelse:rolleregister')));
        self::assertCount(1, array_filter($typer, static fn (string $t): bool => str_ends_with($t, ':deaktivert')));
        $dok = $reg3->dokument();
        self::assertSame('bivrost-rolleregister', $dok['format']);
        self::assertSame([], Skjema::feil('rolleregister', $dok));
    }

    public function testSpraketsRekkefolgeILagretJsonGirIngenEndring(): void
    {
        // MySQL lagrer JSON-objekter med nøklene sortert ({"en","nb","nn"}), MariaDB slik de ble skrevet.
        [, $reg, $logg] = $this->oppsett([['navn' => 'arkivar', 'tekst' => ['nb' => 'Arkivar', 'nn' => 'Arkivar', 'en' => 'Archivist']]]);
        self::assertCount(5, $reg->synk($logg));
        foreach ($this->db->alle('SELECT urn, tekst FROM bivrost_rolle') as $r) {
            $t = json_decode(Felt::tekst($r['tekst']), true);
            self::assertIsArray($t);
            krsort($t, SORT_STRING);
            $this->db->utfor('UPDATE bivrost_rolle SET tekst = ? WHERE urn = ?', [json_encode($t, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), Felt::tekst($r['urn'])]);
        }
        $this->db->utfor("DELETE FROM bivrost_innstilling WHERE nokkel = 'rolleregister.hash'");
        [, $reg2, $logg2] = $this->oppsett([['navn' => 'arkivar', 'tekst' => ['nb' => 'Arkivar', 'nn' => 'Arkivar', 'en' => 'Archivist']]]);
        self::assertSame([], $reg2->synk($logg2), 'Samme tekster i en annen rekkefølge er ingen endring.');
    }

    public function testRbacHierarkiSeparasjonOgDeaktiverteRoller(): void
    {
        [$app, $reg] = $this->oppsett([['navn' => 'arkivar', 'tekst' => 'Arkivar'], ['navn' => 'godkjenner', 'tekst' => 'Godkjenner']], ['separasjon' => [
            ['navn' => 'registrere-godkjenne', 'urner' => ['urn:bivrost:app:gerd:rolle:arkivar', 'urn:bivrost:app:gerd:rolle:godkjenner'], 'maks' => 1],
        ]]);
        $rbac = new Rbac($app, $reg);
        $i = static fn (array $u): Innlogget => new Innlogget(Aktor::bruker('s', 'S'), array_values(array_filter($u, 'is_string')), 'oidc', 0);
        self::assertTrue($rbac->har($i([Urn::BRUKERADMINISTRATOR]), Urn::appTilgang('gerd', 'brukeradministrator')));
        self::assertFalse($rbac->har($i([Urn::BRUKERADMINISTRATOR]), Urn::DRIFT));
        self::assertFalse($rbac->har($i(['urn:bivrost:app:gerd:rolle:arkivar']), Urn::appTilgang('gerd', 'appadministrator')), 'En fagrolle gir ingen tilgang.');
        self::assertFalse($rbac->har($i(['urn:bivrost:app:gerd:rolle:ukjent']), 'urn:bivrost:app:gerd:rolle:ukjent'));
        $begge = ['urn:bivrost:app:gerd:rolle:arkivar', 'urn:bivrost:app:gerd:rolle:godkjenner', Urn::DRIFT];
        self::assertSame([Urn::DRIFT], $rbac->effektive($begge), 'Ved brudd på SSD gir ingen av rollene i regelen tilgang.');
        self::assertCount(1, $rbac->brudd($begge));
        $this->expectException(\Bivrost\Kjerne\Feil\HttpFeil::class);
        $rbac->krev(null, Urn::DRIFT);
    }
}
