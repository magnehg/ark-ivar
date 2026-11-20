<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\Arkiv\Oppgavesett;
use ArkIvar\Tester\Stotte\AppTestCase;
use ArkIvar\Tester\Stotte\Nettleser;
use Bivrost\Kjerne\Database\Felt;

/**
 * Øvingsarkivet ende til ende: arkivet som følger med, postmottaket, registrering og journalføring, vurderingen mot
 * fasiten, den offentlige journalen, angring og nullstilling, og at arkivene er adskilt mellom brukerne.
 *
 * @phpstan-import-type Oppgave from Oppgavesett
 */
final class ArkivTest extends AppTestCase
{
    private Nettleser $kari;

    protected function setUp(): void
    {
        parent::setUp();
        // Eksempeldokumentene er datert i november 2026; dokumentdatoen kan ikke ligge frem i tid.
        $this->klokke->sett('2026-11-20 09:00:00');
        $this->godkjennDomene();
        $this->kari = $this->student('kari@hogskolen.example');
    }

    /** ID-en til saken med tittelen som begynner slik. */
    private function sakId(Nettleser $n, string $tittel): string
    {
        [, $j] = $n->kall('GET', '/api/arkiv/saker');
        foreach (self::rader($j['saker']) as $sak) {
            if (str_starts_with(Felt::tekst($sak['tittel']), $tittel)) {
                return Felt::tekst($sak['id']);
            }
        }
        self::fail('Fant ikke saken: ' . $tittel);
    }

    /**
     * Svaret som følger fasiten for et dokument, slik en student ville fylt ut skjemaet.
     *
     * @param Oppgave $oppgave
     * @return array<string, mixed>
     */
    private function fasitsvar(Nettleser $n, array $oppgave): array
    {
        $fasit = $oppgave['fasit'];
        $sak = $fasit['sak'];
        if ($sak === null) {
            return ['handling' => $fasit['handling']];
        }
        $sakssvar = ['type' => 'ny', 'tittel' => (string) $sak['tittel'], 'arkivdel' => $sak['arkivdel'], 'klasse' => $sak['klasse'][0], 'enhet' => 'DOK',
            'skjerm_tittel' => $sak['offentlig_tittel'] !== null, 'offentlig_tittel' => $sak['offentlig_tittel']];
        if ($sak['type'] === 'eksisterende') {
            $id = Felt::tekst($this->db->verdi('SELECT s.id FROM arkivar_sak s JOIN arkivar_arkiv a ON a.id = s.arkiv_id JOIN arkivar_bruker b ON b.id = a.eier_id WHERE s.nokkel = ? AND b.epost = ?', [$sak['nokkel'], 'kari@hogskolen.example']));
            $sakssvar = ['type' => 'eksisterende', 'id' => $id];
        }

        return [
            'handling' => $fasit['handling'],
            'sak' => $sakssvar,
            'journalpost' => [
                'type' => $fasit['type'] ?? 'I',
                'tittel' => (string) $fasit['tittel'],
                'offentlig_tittel' => $fasit['offentlig_tittel'],
                'dokumentdato' => $oppgave['dokumentdato'],
                'part_navn' => $oppgave['avsender']['navn'],
                'tilgangskode' => $fasit['tilgangskode'][0] ?? '',
                'skjerm_tittel' => $fasit['skjerm_tittel'] === true,
                'skjerm_part' => $fasit['skjerm_part'] === true,
            ],
        ];
    }

    public function testArkivetFolgerMedSakerOgStruktur(): void
    {
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(['saker' => 6, 'journalposter' => 12, 'behandlet' => 0, 'poeng' => 0, 'maks' => 0, 'dokumenter' => 12], $j['oversikt']);
        self::assertIsArray($j['virksomhet']);
        self::assertSame('Sirevåg kommune', $j['virksomhet']['navn']);

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/saker');
        $saker = self::rader($j['saker']);
        self::assertCount(6, $saker);
        self::assertSame('2026/6', $saker[0]['saksnummer'], 'Nyeste sak først.');
        $bygg = array_column($saker, null, 'saksnummer')['2026/1'];
        self::assertStringStartsWith('Byggesak – tilbygg', Felt::tekst($bygg['tittel']));
        self::assertSame('520 Byggesaker', $bygg['klasse_tekst']);
        self::assertSame('Tobias Hadland', $bygg['saksansvarlig_navn']);
        self::assertSame(3, $bygg['antall']);
        self::assertTrue($bygg['fulgte_med']);

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/saker/' . Felt::tekst($bygg['id']));
        $poster = self::rader($j['journalposter']);
        self::assertSame([1, 2, 3], array_column($poster, 'postnr'));
        self::assertSame(['I', 'U', 'U'], array_column($poster, 'type'));

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/struktur');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertCount(3, self::rader($j['arkivdeler']));
        self::assertCount(47, self::rader($j['klasser']));
        self::assertContains('Offl. § 25 første ledd', array_column(self::rader($j['tilgangskoder']), 'hjemmel'));
    }

    public function testPostmottaketViserDokumenteneUtenFasit(): void
    {
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak');
        $dokumenter = self::rader($j['dokumenter']);
        self::assertCount(12, $dokumenter);
        self::assertNotContains(true, array_column($dokumenter, 'behandlet'));
        self::assertSame('aarsmelding-idrettslag', $dokumenter[0]['id'], 'Eldste mottatt først.');

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/tilrettelegging-elev');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertFalse($j['behandlet']);
        self::assertIsArray($j['dokument']);
        self::assertSame('Hilde og Rune Skretting', self::rader([$j['dokument']['avsender']])[0]['navn']);
        self::assertStringNotContainsString('fasit', $svar->kropp);
        self::assertStringNotContainsString('forklaring', $svar->kropp);
        self::assertStringNotContainsString('13-1', $svar->kropp, 'Ingenting fra fasiten følger med før dokumentet er behandlet.');
        [$svar] = $this->kari->kall('GET', '/api/arkiv/postmottak/finnes-ikke');
        self::assertSame(404, $svar->status);
    }

    public function testFasitenGirFullUttellingForAlleDokumentene(): void
    {
        foreach ((new Oppgavesett())->alle() as $oppgave) {
            [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/' . $oppgave['id'], $this->fasitsvar($this->kari, $oppgave));
            self::assertSame(201, $svar->status, $oppgave['id'] . ': ' . $svar->kropp);
            $feil = array_column(array_filter(self::rader($j['vurdering']), static fn (array $k): bool => $k['ok'] !== true), 'id');
            self::assertSame([], $feil, $oppgave['id'] . ': fasiten skal gi riktig på alle kriteriene.');
            self::assertSame($j['maks'], $j['poeng'], $oppgave['id']);
        }
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertIsArray($j['oversikt']);
        self::assertSame(12, $j['oversikt']['behandlet']);
        self::assertSame($j['oversikt']['maks'], $j['oversikt']['poeng']);
        self::assertSame(11, $j['oversikt']['saker'], 'Fem av dokumentene starter en ny sak.');
    }

    public function testJournalforingISkjermetSakOgOffentligJournal(): void
    {
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/tilrettelegging-elev', [
            'handling' => 'journalfor',
            'sak' => ['type' => 'ny', 'tittel' => 'Elevmappe – Mathias Skretting', 'arkivdel' => 'ELEV', 'klasse' => '330', 'enhet' => 'OPP', 'skjerm_tittel' => true, 'offentlig_tittel' => 'Elevmappe – *****'],
            'journalpost' => ['type' => 'I', 'tittel' => 'Søknad om tilrettelagt opplæring – Mathias Skretting', 'offentlig_tittel' => 'Søknad om tilrettelagt opplæring – *****',
                'dokumentdato' => '2026-11-10', 'part_navn' => 'Hilde og Rune Skretting', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true],
        ]);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertSame($j['maks'], $j['poeng'], $svar->kropp);
        self::assertIsArray($j['sak']);
        self::assertSame('2026/7', $j['sak']['saksnummer']);
        self::assertSame('Marte Refsland', $j['sak']['saksansvarlig_navn'], 'Saksansvarlig er den første i enheten.');
        self::assertIsArray($j['journalpost']);
        self::assertSame('2026/13', $j['journalpost']['lopenummer']);
        self::assertSame('J', $j['journalpost']['status']);
        self::assertSame('2026-11-20', $j['journalpost']['journaldato']);

        // Journalen for arkivtjenesten viser alt; den offentlige journalen skjermer tittelen og avsenderen.
        [, $j] = $this->kari->kall('GET', '/api/arkiv/journal');
        $forst = self::rader($j['journalposter'])[0];
        self::assertSame('Søknad om tilrettelagt opplæring – Mathias Skretting', $forst['tittel']);
        self::assertSame('Hilde og Rune Skretting', $forst['part_navn']);
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/journal?offentlig=1');
        $forst = self::rader($j['journalposter'])[0];
        self::assertSame('Søknad om tilrettelagt opplæring – *****', $forst['tittel']);
        self::assertSame('*****', $forst['part_navn']);
        self::assertSame('Elevmappe – *****', $forst['sakstittel']);
        self::assertStringContainsString('Offl. § 13 første ledd', Felt::tekst($forst['hjemmel']));
        self::assertStringNotContainsString('Skretting', $svar->kropp, 'Navnet står ingen steder i den offentlige journalen.');
    }

    public function testFeilValgGirForklaringOgNavnIOffentligTittelOppdages(): void
    {
        // Klagen legges i en ny sak med feil klasse, og tittelen skjermes uten grunn.
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/klage-fyrveien', [
            'handling' => 'journalfor',
            'sak' => ['type' => 'ny', 'tittel' => 'Klage fra nabo', 'arkivdel' => 'SAK', 'klasse' => '510', 'enhet' => 'PBE'],
            'journalpost' => ['type' => 'U', 'tittel' => 'Klage', 'dokumentdato' => '2026-11-16', 'part_navn' => 'Solveig Mæland', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => false],
        ]);
        self::assertSame(201, $svar->status, $svar->kropp);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertTrue($vurdering['handling']['ok']);
        foreach (['sak', 'klasse', 'dokumentdato', 'type', 'tilgang', 'skjerm_tittel'] as $id) {
            self::assertFalse($vurdering[$id]['ok'], $id);
        }
        self::assertTrue($vurdering['arkivdel']['ok']);
        self::assertTrue($vurdering['part']['ok']);
        self::assertTrue($vurdering['skjerm_part']['ok']);
        self::assertStringContainsString('samme saksmappen', Felt::tekst($vurdering['sak']['forklaring']));
        self::assertSame('510 Reguleringsplaner', $vurdering['klasse']['ditt']);
        self::assertSame('520 Byggesaker', $vurdering['klasse']['riktig']);
        self::assertSame(4, $j['poeng']);
        self::assertSame(10, $j['maks']);
        self::assertIsArray($j['forslag']);
        self::assertStringStartsWith('2026/1 Byggesak – tilbygg til bolig', Felt::tekst($j['forslag']['sak']));

        // Varselet: navnet på den det varsles om, står i tittelen uten skjerming.
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/varsel-arbeidsmiljo', [
            'handling' => 'journalfor',
            'sak' => ['type' => 'ny', 'tittel' => 'Varsling – omsorgssenteret', 'arkivdel' => 'SAK', 'klasse' => '220', 'enhet' => 'HR'],
            'journalpost' => ['type' => 'I', 'tittel' => 'Varsel om avdelingsleder Svein Lode', 'dokumentdato' => '2026-11-14', 'part_navn' => 'Camilla Hobberstad', 'tilgangskode' => '13-1', 'skjerm_tittel' => false, 'skjerm_part' => true],
        ]);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertFalse($vurdering['offentlig_tittel']['ok'], 'Den offentlige tittelen røper et navn.');
        self::assertTrue($vurdering['skjerm_part']['ok']);

        // En ny sak med navn i tittelen og uten skjerming røper navnet, selv om journalposten er skjermet.
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/tilrettelegging-elev', [
            'handling' => 'journalfor',
            'sak' => ['type' => 'ny', 'tittel' => 'Elevmappe – Mathias Skretting', 'arkivdel' => 'ELEV', 'klasse' => '330', 'enhet' => 'OPP'],
            'journalpost' => ['type' => 'I', 'tittel' => 'Søknad om tilrettelagt opplæring – Mathias Skretting', 'dokumentdato' => '2026-11-10', 'part_navn' => 'Hilde og Rune Skretting',
                'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true],
        ]);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertFalse($vurdering['offentlig_sakstittel']['ok'], 'Sakstittelen røper navnet.');
        self::assertTrue($vurdering['offentlig_tittel']['ok']);

        // Feil behandling: bare behandlingen vurderes.
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/kursinvitasjon', [
            'handling' => 'journalfor',
            'sak' => ['type' => 'ny', 'tittel' => 'Kurs i dokumenthåndtering', 'arkivdel' => 'SAK', 'klasse' => '030', 'enhet' => 'DOK'],
            'journalpost' => ['type' => 'I', 'tittel' => 'Invitasjon til kurs', 'dokumentdato' => '2026-11-16', 'part_navn' => 'Nordisk Kurs og Kompetanse AS', 'tilgangskode' => ''],
        ]);
        self::assertSame(0, $j['poeng']);
        self::assertSame(1, $j['maks']);
        self::assertStringContainsString('reklame', Felt::tekst(self::rader($j['vurdering'])[0]['forklaring']));
    }

    public function testSkjemaetValideres(): void
    {
        $gyldig = [
            'handling' => 'journalfor',
            'sak' => ['type' => 'ny', 'tittel' => 'Byggesak – garasje – Strandveien 14', 'arkivdel' => 'SAK', 'klasse' => '520', 'enhet' => 'PBE'],
            'journalpost' => ['type' => 'I', 'tittel' => 'Søknad om dispensasjon', 'dokumentdato' => '2026-11-12', 'part_navn' => 'Gudmund Refsnes', 'tilgangskode' => ''],
        ];
        $feil = [
            'skjerming_uten_hjemmel' => ['journalpost' => ['skjerm_tittel' => true] + $gyldig['journalpost']],
            'ugyldig_dato' => ['journalpost' => ['dokumentdato' => '2026-11-21'] + $gyldig['journalpost']],
        ];
        foreach ($feil as $kode => $endring) {
            [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/dispensasjon-strandveien', $endring + $gyldig);
            self::assertSame(422, $svar->status, $kode);
            self::assertSame($kode, $j['kode']);
        }
        $ugyldige = [
            ['handling' => 'kast'],
            ['sak' => ['klasse' => '5'] + $gyldig['sak']],
            ['sak' => ['arkivdel' => 'XXX'] + $gyldig['sak']],
            ['sak' => ['type' => 'eksisterende', 'id' => 'finnes-ikke']],
            ['journalpost' => ['tittel' => ' '] + $gyldig['journalpost']],
            ['journalpost' => ['part_navn' => ''] + $gyldig['journalpost']],
            ['journalpost' => ['tilgangskode' => '99'] + $gyldig['journalpost']],
            ['journalpost' => ['type' => 'Z'] + $gyldig['journalpost']],
        ];
        foreach ($ugyldige as $nr => $endring) {
            [$svar] = $this->kari->kall('POST', '/api/arkiv/postmottak/dispensasjon-strandveien', $endring + $gyldig);
            self::assertSame(422, $svar->status, "Ugyldig skjema nr. $nr: " . $svar->kropp);
        }
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertIsArray($j['oversikt']);
        self::assertSame(6, $j['oversikt']['saker'], 'Et skjema med feil oppretter ingen sak.');
        self::assertSame(12, $j['oversikt']['journalposter']);
    }

    public function testAngreNullstillOgAdskilteArkiver(): void
    {
        $svaret = [
            'handling' => 'journalfor',
            'sak' => ['type' => 'ny', 'tittel' => 'Byggesak – garasje – Strandveien 14', 'arkivdel' => 'SAK', 'klasse' => '520', 'enhet' => 'PBE'],
            'journalpost' => ['type' => 'I', 'tittel' => 'Søknad om dispensasjon', 'dokumentdato' => '2026-11-12', 'part_navn' => 'Gudmund Refsnes', 'tilgangskode' => ''],
        ];
        [$svar] = $this->kari->kall('POST', '/api/arkiv/postmottak/dispensasjon-strandveien', $svaret);
        self::assertSame(201, $svar->status, $svar->kropp);
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/dispensasjon-strandveien', $svaret);
        self::assertSame(409, $svar->status);
        self::assertSame('alt_behandlet', $j['kode']);
        $sakId = $this->sakId($this->kari, 'Byggesak – garasje');

        // En annen student har sitt eget arkiv og ser ikke Karis sak.
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        [, $j] = $ola->kall('GET', '/api/arkiv');
        self::assertIsArray($j['oversikt']);
        self::assertSame(6, $j['oversikt']['saker']);
        self::assertSame(0, $j['oversikt']['behandlet']);
        [$svar] = $ola->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertSame(404, $svar->status);
        [$svar] = $ola->kall('POST', '/api/arkiv/postmottak/klage-fyrveien', ['handling' => 'journalfor', 'sak' => ['type' => 'eksisterende', 'id' => $sakId],
            'journalpost' => ['type' => 'I', 'tittel' => 'Klage på vedtak', 'dokumentdato' => '2026-11-17', 'part_navn' => 'Solveig Mæland', 'tilgangskode' => '']]);
        self::assertSame(422, $svar->status, 'En sak i et annet arkiv kan ikke brukes.');

        // Angre: journalposten og saken som ble opprettet for dokumentet, forsvinner, og dokumentet kan behandles på nytt.
        [$svar] = $this->kari->kall('DELETE', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertSame(404, $svar->status);
        [, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertFalse($j['behandlet']);
        [$svar] = $this->kari->kall('POST', '/api/arkiv/postmottak/dispensasjon-strandveien', $svaret);
        self::assertSame(201, $svar->status, $svar->kropp);

        // Angre i en sak som fantes: saken blir stående.
        [$svar] = $this->kari->kall('POST', '/api/arkiv/postmottak/klage-fyrveien', ['handling' => 'journalfor', 'sak' => ['type' => 'eksisterende', 'id' => $this->sakId($this->kari, 'Byggesak – tilbygg')],
            'journalpost' => ['type' => 'I', 'tittel' => 'Klage på vedtak', 'dokumentdato' => '2026-11-17', 'part_navn' => 'Solveig Mæland', 'tilgangskode' => '']]);
        self::assertSame(201, $svar->status, $svar->kropp);
        $this->kari->kall('DELETE', '/api/arkiv/postmottak/klage-fyrveien');
        [, $j] = $this->kari->kall('GET', '/api/arkiv/saker/' . $this->sakId($this->kari, 'Byggesak – tilbygg'));
        self::assertCount(3, self::rader($j['journalposter']));

        // Nullstilling: arkivet er som da studenten fikk det.
        [$svar] = $this->kari->kall('POST', '/api/arkiv/nullstill', []);
        self::assertSame(200, $svar->status, $svar->kropp);
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertSame(['saker' => 6, 'journalposter' => 12, 'behandlet' => 0, 'poeng' => 0, 'maks' => 0, 'dokumenter' => 12], $j['oversikt']);

        // Driftsbrukeren og den som ikke er logget inn, har ikke noe arkiv.
        [$svar] = $this->drift()->kall('GET', '/api/arkiv');
        self::assertSame(403, $svar->status);
        $ukjent = $this->nettleser('10.0.0.9');
        $ukjent->start();
        [$svar] = $ukjent->kall('GET', '/api/arkiv/postmottak');
        self::assertSame(401, $svar->status);
    }
}
