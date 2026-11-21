<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\Arkiv\Dokumentmaler;
use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Oppgavesett;
use ArkIvar\Arkiv\Postmottak;
use ArkIvar\Arkiv\Register;
use ArkIvar\Arkiv\Skriving;
use ArkIvar\Tester\Stotte\AppTestCase;
use ArkIvar\Tester\Stotte\Bakgrunnsprosess;
use ArkIvar\Tester\Stotte\Nettleser;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Tester\Stotte\TestDb;

/**
 * Samtidighet med flere prosesser: to forespørsler i drift er to PHP-prosesser med hver sin databaseforbindelse.
 * ArkivTest viser at hver operasjon venter på låsen; her fullfører de som venter, og resultatet kontrolleres.
 *
 * Rekkefølgen er bestemt uten pauser: testen holder selv låsen prosessene trenger, starter dem, ser i databasen at
 * hver av dem står i kø bak låsen (Bakgrunnsprosess::ventTilDenVenter), og slipper den så.
 */
final class SamtidighetTest extends AppTestCase
{
    /** Navnet på låsen i Ovingsarkiv (NULLSTILLING): første åpning og nullstilling går etter hverandre. */
    private const NULLSTILLING = 'arkivar-nullstilling';

    private Nettleser $kari;
    private Grunndata $grunndata;

    /** En forbindelse som bare ser etter hvem som venter: den holder ingen låser og er ikke i en transaksjon. */
    private Db $vakt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grunndata = new Grunndata();
        // Eksempeldokumentene er datert i november 2026; dokumentdatoen kan ikke ligge frem i tid.
        $this->klokke->sett('2026-11-20 09:00:00');
        $this->godkjennDomene();
        $this->kari = $this->student('kari@hogskolen.example');
        $this->vakt = TestDb::kobleTil($this->dbNavn);
    }

    /** Starter en operasjon i en egen prosess. */
    private function prosess(string $operasjon, mixed ...$argumenter): Bakgrunnsprosess
    {
        return Bakgrunnsprosess::start($this->dbNavn, $this->klokke->now(), $operasjon, array_values($argumenter));
    }

    /** Arkivet til brukeren med e-postadressen (tom tekst når brukeren ikke har noe arkiv). */
    private function arkivId(string $epost): string
    {
        return Felt::tekst($this->db->verdi('SELECT a.id FROM arkivar_arkiv a JOIN arkivar_bruker b ON b.id = a.eier_id WHERE b.epost = ?', [$epost]));
    }

    /** @param array<string, mixed> $mappe */
    private function nyMappe(Nettleser $n, array $mappe): string
    {
        [$svar, $j] = $n->kall('POST', '/api/arkiv/saker', $mappe);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['sak']);

        return Felt::tekst($j['sak']['id']);
    }

    /** Krever at arkivet har akkurat det som følger med: hver mappe og hver journalpost én gang, og ingenting annet. */
    private function assertSomNytt(string $arkivId): void
    {
        $nokler = array_column($this->grunndata->saker(), 'nokkel');
        sort($nokler);
        $iArkivet = array_map(static fn (array $rad): string => Felt::tekst($rad['nokkel']), $this->db->alle('SELECT nokkel FROM arkivar_sak WHERE arkiv_id = ? ORDER BY nokkel', [$arkivId]));
        sort($iArkivet);
        self::assertSame($nokler, $iArkivet, 'Arkivet har mappene som følger med, én gang hver, og ingen andre.');
        $dokumenter = [];
        foreach ($this->grunndata->saker() as $sak) {
            foreach ($sak['journalposter'] as $post) {
                $dokumenter[] = $sak['nokkel'] . '#' . $post['nr'];
            }
        }
        sort($dokumenter);
        $poster = array_map(
            static fn (array $rad): string => Felt::tekst($rad['nokkel']) . '#' . Felt::tall($rad['postnr']),
            $this->db->alle('SELECT s.nokkel, j.postnr FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id WHERE j.arkiv_id = ?', [$arkivId]),
        );
        sort($poster);
        self::assertSame($dokumenter, $poster, 'Arkivet har journalpostene som følger med, én gang hver, og ingen andre.');
        self::assertSame(count($nokler), Felt::tall($this->db->verdi('SELECT sak_sekvens FROM arkivar_arkiv WHERE id = ?', [$arkivId])), 'Saksnumrene er brukt én gang.');
        self::assertSame(count($dokumenter), Felt::tall($this->db->verdi('SELECT journal_sekvens FROM arkivar_arkiv WHERE id = ?', [$arkivId])), 'Løpenumrene er brukt én gang.');
        self::assertSame(0, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_oppgavestatus WHERE arkiv_id = ?', [$arkivId])));
    }

    public function testToProsesserSomAapnerArkivetForsteGangFaarSammeArkiv(): void
    {
        // Forsiden henter flere ting samtidig første gang arkivet åpnes. Begge prosessene ser at brukeren ikke har
        // noe arkiv, og står så i kø bak låsen testen holder. Når den slippes, lager den ene arkivet og den andre
        // bruker det.
        $brukerId = Felt::tekst($this->db->verdi('SELECT id FROM arkivar_bruker WHERE epost = ?', ['kari@hogskolen.example']));
        self::assertNotSame('', $brukerId);
        self::assertSame('', $this->arkivId('kari@hogskolen.example'), 'Brukeren har ikke åpnet arkivet ennå.');
        self::assertTrue($this->db->las(self::NULLSTILLING, 5));
        try {
            $en = $this->prosess(Bakgrunnsprosess::FOR_BRUKER, $brukerId);
            $to = $this->prosess(Bakgrunnsprosess::FOR_BRUKER, $brukerId);
            self::assertTrue($en->ventTilDenVenter($this->vakt), 'Den første prosessen venter på låsen.');
            self::assertTrue($to->ventTilDenVenter($this->vakt), 'Den andre prosessen venter på låsen.');
        } finally {
            $this->db->slippLas(self::NULLSTILLING);
        }
        self::assertTrue($en->resultat()['ok'], $en->forklaring());
        self::assertTrue($to->resultat()['ok'], $to->forklaring());
        $arkivId = $this->arkivId('kari@hogskolen.example');
        self::assertNotSame('', $arkivId);
        self::assertSame($arkivId, $en->resultat()['verdi']);
        self::assertSame($arkivId, $to->resultat()['verdi'], 'Begge får det samme arkivet.');
        self::assertSame(1, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_arkiv WHERE eier_id = ?', [$brukerId])));
        $this->assertSomNytt($arkivId);
    }

    public function testSammeDokumentFraToProsesserRegistreresEnGang(): void
    {
        // Dobbel innsending: den første registreringen står åpen i en transaksjon med låsen på arkivet (her i
        // testens egen forbindelse), og den andre kommer fra en annen prosess. Den andre venter på låsen, og
        // sjekken av om dokumentet er behandlet, gjøres først når den første er lagret.
        $this->kari->kall('GET', '/api/arkiv');
        $arkivId = $this->arkivId('kari@hogskolen.example');
        $sakId = $this->nyMappe($this->kari, ['tittel' => 'Byggesak – garasje – Strandveien 14', 'arkivdel' => 'EIENDOM', 'klasse' => '12/45', 'enhet' => 'PBE']);
        $post = ['oppgave' => 'dispensasjon-strandveien', 'journalfores' => true, 'type' => 'I', 'tittel' => 'Søknad om dispensasjon', 'dokumentdato' => '2026-11-12', 'part_navn' => 'Gudmund Refsnes', 'tilgangskode' => ''];
        $register = new Register($this->db, $this->klokke, $this->grunndata);
        $postmottak = new Postmottak($this->db, $this->klokke, $this->grunndata, new Oppgavesett(), $register, new Skriving($this->db, $this->klokke, $this->grunndata, new Dokumentmaler(), $register));
        $andre = null;
        $forste = $this->db->transaksjon(function () use ($register, $postmottak, $arkivId, $sakId, $post, &$andre): array {
            $register->laas($arkivId);
            $andre = $this->prosess(Bakgrunnsprosess::JOURNALPOST, $arkivId, $sakId, $post);
            self::assertTrue($andre->ventTilDenVenter($this->vakt), 'Den andre registreringen venter mens den første står åpen.');

            return $postmottak->journalpost($arkivId, $sakId, $post);
        });
        self::assertSame('dispensasjon-strandveien', $forste['oppgave']);
        self::assertInstanceOf(Bakgrunnsprosess::class, $andre);
        $resultat = $andre->resultat();
        self::assertFalse($resultat['ok'], 'Den andre registreringen avvises: ' . $andre->forklaring());
        self::assertSame([409, 'alt_behandlet'], [$resultat['status'], $resultat['kode']], $andre->forklaring());
        self::assertSame(1, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_journalpost WHERE sak_id = ?', [$sakId])), 'Mappen har én journalpost.');
        self::assertSame(1, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, 'dispensasjon-strandveien'])));
        self::assertSame(
            $forste['journalpost']['id'],
            $this->db->verdi('SELECT journalpost_id FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, 'dispensasjon-strandveien']),
            'Behandlingen peker på journalposten fra den første registreringen.',
        );
    }

    public function testToMapperFraToProsesserFaarHvertSittSaksnummer(): void
    {
        $this->kari->kall('GET', '/api/arkiv');
        $arkivId = $this->arkivId('kari@hogskolen.example');
        $siste = Felt::tall($this->db->verdi('SELECT sak_sekvens FROM arkivar_arkiv WHERE id = ?', [$arkivId]));
        $register = new Register($this->db, $this->klokke, $this->grunndata);
        $mappe = ['arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK'];
        [$en, $to] = $this->db->transaksjon(function () use ($register, $arkivId, $mappe): array {
            $register->laas($arkivId);
            $en = $this->prosess(Bakgrunnsprosess::OPPRETT_MAPPE, $arkivId, ['tittel' => 'Mappe fra den ene fanen'] + $mappe);
            $to = $this->prosess(Bakgrunnsprosess::OPPRETT_MAPPE, $arkivId, ['tittel' => 'Mappe fra den andre fanen'] + $mappe);
            self::assertTrue($en->ventTilDenVenter($this->vakt), 'Den første mappen venter på låsen på arkivet.');
            self::assertTrue($to->ventTilDenVenter($this->vakt), 'Den andre mappen venter på låsen på arkivet.');

            return [$en, $to];
        });
        self::assertTrue($en->resultat()['ok'], $en->forklaring());
        self::assertTrue($to->resultat()['ok'], $to->forklaring());
        $numre = [];
        foreach ([$en, $to] as $prosess) {
            $rad = $this->db->en('SELECT saksaar, sekvens FROM arkivar_sak WHERE arkiv_id = ? AND id = ?', [$arkivId, Felt::tekst($prosess->resultat()['verdi'])]);
            self::assertNotNull($rad, 'Mappen er lagret.');
            self::assertSame(2026, Felt::tall($rad['saksaar']));
            $numre[] = Felt::tall($rad['sekvens']);
        }
        sort($numre);
        self::assertSame([$siste + 1, $siste + 2], $numre, 'Mappene får de to neste saksnumrene, ett hver.');
        self::assertSame($siste + 2, Felt::tall($this->db->verdi('SELECT sak_sekvens FROM arkivar_arkiv WHERE id = ?', [$arkivId])));
    }

    public function testNullstillingerFraToProsesserGaarEtterHverandre(): void
    {
        // En hel klasse nullstiller etter at innholdet er oppdatert: nullstillingene i ulike arkiver går etter
        // hverandre bak en felles lås. Testen holder den låsen, så begge prosessene må stå i kø før de slipper til.
        $this->kari->kall('GET', '/api/arkiv');
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        $ola->kall('GET', '/api/arkiv');
        $arkiver = [$this->arkivId('kari@hogskolen.example'), $this->arkivId('ola@hogskolen.example')];
        self::assertNotSame($arkiver[0], $arkiver[1]);
        foreach ([$this->kari, $ola] as $n) {
            $this->nyMappe($n, ['tittel' => 'Mappe som skal bort', 'arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK']);
            [$svar] = $n->kall('POST', '/api/arkiv/postmottak/kursinvitasjon', ['handling' => 'ikke_arkiver']);
            self::assertSame(201, $svar->status, $svar->kropp);
        }
        self::assertTrue($this->db->las(self::NULLSTILLING, 5));
        try {
            $en = $this->prosess(Bakgrunnsprosess::NULLSTILL, $arkiver[0]);
            $to = $this->prosess(Bakgrunnsprosess::NULLSTILL, $arkiver[1]);
            self::assertTrue($en->ventTilDenVenter($this->vakt), 'Den første nullstillingen venter på den felles låsen.');
            self::assertTrue($to->ventTilDenVenter($this->vakt), 'Den andre nullstillingen venter på den felles låsen.');
            self::assertSame(2, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_sak WHERE tittel = ?', ['Mappe som skal bort'])), 'Ingenting er slettet mens de venter.');
        } finally {
            $this->db->slippLas(self::NULLSTILLING);
        }
        self::assertTrue($en->resultat()['ok'], $en->forklaring());
        self::assertTrue($to->resultat()['ok'], $to->forklaring());
        foreach ($arkiver as $arkivId) {
            $this->assertSomNytt($arkivId);
            self::assertNotNull($this->db->verdi('SELECT nullstilt FROM arkivar_arkiv WHERE id = ?', [$arkivId]));
        }
    }
}
