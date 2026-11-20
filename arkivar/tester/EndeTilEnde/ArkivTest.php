<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Mapper;
use ArkIvar\Arkiv\Oppgavesett;
use ArkIvar\Arkiv\Ovingsarkiv;
use ArkIvar\Arkiv\Postmottak;
use ArkIvar\Arkiv\Register;
use ArkIvar\Tester\Stotte\AppTestCase;
use ArkIvar\Tester\Stotte\Nettleser;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Tester\Stotte\TestDb;

/**
 * Øvingsarkivet ende til ende: arkivet som følger med, søket i mappene, mapper som opprettes først og får
 * journalposter etterpå, postmottaket, vurderingen mot fasiten, den offentlige journalen, angring og nullstilling,
 * og at arkivene er adskilt mellom brukerne.
 *
 * @phpstan-import-type Oppgave from Oppgavesett
 */
final class ArkivTest extends AppTestCase
{
    private Nettleser $kari;
    private Grunndata $grunndata;
    private Oppgavesett $oppgaver;

    /**
     * Tallene på forsiden for et arkiv ingen har rørt: mappene og journalpostene som følger med, og postmottaket.
     *
     * @return array{saker: int, journalposter: int, behandlet: int, poeng: int, maks: int, utdatert: bool, dokumenter: int}
     */
    private function urort(): array
    {
        $poster = array_merge(...array_column($this->grunndata->saker(), 'journalposter'));

        return [
            'saker' => count($this->grunndata->saker()),
            'journalposter' => count(array_filter($poster, static fn (array $p): bool => $p['journalfort'])),
            'behandlet' => 0, 'poeng' => 0, 'maks' => 0, 'utdatert' => false,
            'dokumenter' => count($this->oppgaver->alle()),
        ];
    }

    /**
     * Vurderingen av et behandlet dokument slik postmottaket viser den nå, etter kriterium.
     *
     * @return array{poeng: int, maks: int, kriterier: array<string, array<string, mixed>>}
     */
    private function vurdering(Nettleser $n, string $oppgaveId): array
    {
        [$svar, $j] = $n->kall('GET', '/api/arkiv/postmottak/' . $oppgaveId);
        self::assertSame(200, $svar->status, $svar->kropp);
        $kriterier = [];
        foreach (self::rader($j['vurdering']) as $k) {
            $kriterier[Felt::tekst($k['id'])] = $k;
        }

        return ['poeng' => Felt::tall($j['poeng']), 'maks' => Felt::tall($j['maks']), 'kriterier' => $kriterier];
    }

    /** Koden i registeret til arkivdelen for den som heter dette («Etternavn, Fornavn»). */
    private function registerkode(string $arkivdel, string $tittel): string
    {
        foreach ($this->grunndata->register($arkivdel) as $post) {
            if ($post['tittel'] === $tittel) {
                return $post['kode'];
            }
        }
        self::fail("Fant ikke $tittel i registeret til $arkivdel.");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->grunndata = new Grunndata();
        $this->oppgaver = new Oppgavesett();
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
     * Oppretter en mappe og gir ID-en.
     *
     * @param array<string, mixed> $mappe
     */
    private function nyMappe(Nettleser $n, array $mappe): string
    {
        [$svar, $j] = $n->kall('POST', '/api/arkiv/saker', $mappe);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['sak']);

        return Felt::tekst($j['sak']['id']);
    }

    /**
     * Registrerer et dokument fra postmottaket som en journalpost i mappen, og gir vurderingen slik postmottaket
     * viser den etterpå.
     *
     * @param array<string, mixed> $journalpost
     * @return array<string, mixed>
     */
    private function registrer(Nettleser $n, string $sakId, string $oppgaveId, array $journalpost): array
    {
        [$svar] = $n->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['oppgave' => $oppgaveId] + $journalpost);
        self::assertSame(201, $svar->status, $oppgaveId . ': ' . $svar->kropp);
        [$svar, $j] = $n->kall('GET', '/api/arkiv/postmottak/' . $oppgaveId);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertTrue($j['behandlet']);

        return $j;
    }

    /**
     * Behandler et dokument slik fasiten sier, slik en student ville gjort det: mappen finnes eller opprettes først,
     * og dokumentet registreres i den. Gir vurderingen.
     *
     * @param Oppgave $oppgave
     * @return array<string, mixed>
     */
    private function folgFasiten(Nettleser $n, array $oppgave): array
    {
        $fasit = $oppgave['fasit'];
        $sak = $fasit['sak'];
        if ($sak === null) {
            [$svar, $j] = $n->kall('POST', '/api/arkiv/postmottak/' . $oppgave['id'], ['handling' => $fasit['handling']]);
            self::assertSame(201, $svar->status, $oppgave['id'] . ': ' . $svar->kropp);

            return $j;
        }
        if ($sak['type'] === 'eksisterende') {
            $sakId = Felt::tekst($this->db->verdi('SELECT s.id FROM arkivar_sak s JOIN arkivar_arkiv a ON a.id = s.arkiv_id JOIN arkivar_bruker b ON b.id = a.eier_id WHERE s.nokkel = ? AND b.epost = ?', [$sak['nokkel'], 'kari@hogskolen.example']));
        } else {
            // Klasseringen skjermes i personarkivene (fødselsdato og navn) og ellers ikke.
            $person = ($this->grunndata->arkivdel($sak['arkivdel'])['ordning'] ?? '') === Grunndata::PERSON;
            $sakId = $this->nyMappe($n, [
                'tittel' => (string) $sak['tittel'], 'arkivdel' => $sak['arkivdel'], 'klasse' => $sak['klasse'][0], 'enhet' => 'DOK',
                'skjerm_tittel' => $sak['offentlig_tittel'] !== null, 'offentlig_tittel' => $sak['offentlig_tittel'], 'skjerm_klasse' => $person,
                'tilgangskode' => $sak['offentlig_tittel'] !== null || $person ? ($fasit['tilgangskode'][0] ?? '13-1') : '',
            ]);
        }

        return $this->registrer($n, $sakId, $oppgave['id'], [
            'journalfores' => $fasit['handling'] === 'journalfor',
            'type' => $fasit['type'] ?? 'I',
            'tittel' => (string) $fasit['tittel'],
            'offentlig_tittel' => $fasit['offentlig_tittel'],
            'dokumentdato' => $oppgave['dokumentdato'],
            'part_navn' => $oppgave['avsender']['navn'],
            'tilgangskode' => $fasit['tilgangskode'][0] ?? '',
            'skjerm_tittel' => $fasit['skjerm_tittel'] === true,
            'skjerm_part' => $fasit['skjerm_part'] === true,
        ]);
    }

    public function testArkivetFolgerMedSakerOgStruktur(): void
    {
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame($this->urort(), $j['oversikt']);
        self::assertIsArray($j['virksomhet']);
        self::assertSame('Sirevåg kommune', $j['virksomhet']['navn']);

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/saker');
        $saker = self::rader($j['saker']);
        self::assertCount(count($this->grunndata->saker()), $saker);
        // Mappene har fått saksnummer etter saksdatoen, og listen viser den nyeste først.
        $datoer = array_column($saker, 'saksdato');
        $sortert = $datoer;
        rsort($sortert);
        self::assertSame($sortert, $datoer);
        self::assertStringEndsWith('/' . count($saker), Felt::tekst($saker[0]['saksnummer']));
        self::assertStringEndsWith('/1', Felt::tekst($saker[count($saker) - 1]['saksnummer']));
        // Alle seks arkivdelene har mapper.
        self::assertEqualsCanonicalizing(['SAK', 'EIENDOM', 'PERSONAL', 'ELEV', 'BARNEHAGE', 'MOTE'], array_values(array_unique(array_map(Felt::tekst(...), array_column($saker, 'arkivdel')))));

        $bygg = array_values(array_filter($saker, static fn (array $s): bool => str_starts_with(Felt::tekst($s['tittel']), 'Byggesak – tilbygg til bolig – Fyrveien 8')))[0];
        self::assertSame('Eiendomsarkiv', $bygg['arkivdel_tekst']);
        self::assertSame('9/112 Fyrveien 8', $bygg['klasse_tekst']);
        self::assertFalse($bygg['skjerm_klasse']);
        self::assertSame('Gunnar Grevling', $bygg['saksansvarlig_navn']);
        self::assertTrue($bygg['fulgte_med']);
        $startsak = array_column($this->grunndata->saker(), null, 'nokkel')['byggesak-fyrveien-8'];
        self::assertSame(count($startsak['journalposter']), $bygg['antall']);

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/saker/' . Felt::tekst($bygg['id']));
        $poster = self::rader($j['journalposter']);
        self::assertFalse($j['kan_slettes'], 'En mappe som fulgte med, kan ikke slettes.');
        self::assertSame(range(1, count($startsak['journalposter'])), array_column($poster, 'postnr'));
        self::assertSame(array_column($startsak['journalposter'], 'type'), array_column($poster, 'type'));
        self::assertNotContains(false, array_column($poster, 'har_dokument'), 'Alle dokumentene i arkivet kan åpnes.');
        self::assertNotContains(true, array_column($poster, 'kan_slettes'));

        // Dokumentet til en journalpost, med saksnummeret til mappen satt inn i teksten.
        $utgaende = array_values(array_filter($poster, static fn (array $p): bool => $p['type'] === 'U'))[0];
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/journalposter/' . Felt::tekst($utgaende['id']));
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['dokument']);
        self::assertIsArray($j['sak']);
        self::assertSame($bygg['saksnummer'], $j['sak']['saksnummer']);
        self::assertStringStartsWith('Sirevåg kommune', Felt::tekst(self::rader([$j['dokument']['avsender']])[0]['navn']));
        self::assertStringNotContainsString('{{', $svar->kropp, 'Plassholderne for saksnummer er fylt inn.');
        self::assertStringContainsString(Felt::tekst($bygg['saksnummer']), json_encode($j['dokument'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        [$svar] = $this->kari->kall('GET', '/api/arkiv/journalposter/finnes-ikke');
        self::assertSame(404, $svar->status);

        // Journalen har løpenummer i den rekkefølgen dokumentene ble registrert, på tvers av mappene.
        [, $j] = $this->kari->kall('GET', '/api/arkiv/journal');
        $journal = self::rader($j['journalposter']);
        self::assertCount($this->urort()['journalposter'], $journal);
        $etterNummer = $journal;
        usort($etterNummer, static fn (array $a, array $b): int => (int) explode('/', Felt::tekst($a['lopenummer']))[1] <=> (int) explode('/', Felt::tekst($b['lopenummer']))[1]);
        $journaldatoer = array_column($etterNummer, 'journaldato');
        $stigende = $journaldatoer;
        sort($stigende);
        self::assertSame($stigende, $journaldatoer);

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/struktur');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(['SAK', 'EIENDOM', 'PERSONAL', 'ELEV', 'BARNEHAGE', 'MOTE'], array_column(self::rader($j['arkivdeler']), 'kode'));
        self::assertCount(count($this->grunndata->klasser()), self::rader($j['klasser']));
        self::assertIsArray($j['registre']);
        self::assertSame(['EIENDOM', 'PERSONAL', 'ELEV', 'BARNEHAGE', 'MOTE'], array_keys($j['registre']));
        self::assertContains('Offl. § 25 første ledd', array_column(self::rader($j['tilgangskoder']), 'hjemmel'));
        self::assertContains('elevmappe', array_column(self::rader($j['saksmaler']), 'kode'));
    }

    public function testSoketFinnerMappenPaaTittelKlasseringOgDokumentene(): void
    {
        /** @return list<array<string, mixed>> */
        $sok = function (string $q): array {
            [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/sok?q=' . rawurlencode($q));
            self::assertSame(200, $svar->status, $svar->kropp);

            return self::rader($j['treff']);
        };
        $titler = static fn (array $treff): array => array_map(static fn (array $t): string => Felt::tekst(self::rader([$t['sak']])[0]['tittel']), self::rader($treff));

        // Søketipset for elevmapper: navnet til eleven og «elevmappe». Alle ordene må finnes, uansett rekkefølge.
        self::assertSame(['Elevmappe – Måke, Mathias'], $titler($sok('elevmappe måke')));
        self::assertSame(['Elevmappe – Måke, Mathias'], $titler($sok('  MATHIAS   Måke elevmappe ')));
        self::assertCount(count(array_filter($this->grunndata->saker(), static fn (array $s): bool => $s['arkivdel'] === 'ELEV')), $sok('elevmappe'));
        // Tegnsetting rundt ordene teller ikke: navnet limt inn fra registeret, anførselstegn og en løs bindestrek.
        self::assertSame(['Elevmappe – Måke, Mathias'], $titler($sok('Måke, Mathias')));
        self::assertSame(['Elevmappe – Måke, Mathias'], $titler($sok('"elevmappe" - (Måke)')));
        self::assertSame([], $sok('- , .'));
        // Et søk som ikke er gyldig tekst, gir ingen treff og ingen feil.
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/sok?q=' . rawurlencode("m\xE5ke"));
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame([], $j['treff']);
        // Eleven som ikke har mappe: søket gir ingenting, og da kan mappen opprettes.
        self::assertSame([], $sok('elevmappe lerke'));
        // Klasseringen: gårds- og bruksnummer, fødselsdato og arkivnøkkel.
        self::assertContains('Byggesak – tilbygg til bolig – Fyrveien 8 – gnr. 9 bnr. 112', $titler($sok('9/112')));
        self::assertSame(['Elevmappe – Måke, Mathias'], $titler($sok('14.03.2016')));
        self::assertContains('Tilsetting – byggesaksbehandler – stillingsnr. 26/41', $titler($sok('212 tilsetting')));
        self::assertContains('Tilsetting – byggesaksbehandler – stillingsnr. 26/41', $titler($sok('26/41')));
        // Saksnummeret.
        $fyrveien = $sok('fyrveien 8 tilbygg');
        self::assertCount(1, $fyrveien);
        $nummer = Felt::tekst(self::rader([$fyrveien[0]['sak']])[0]['saksnummer']);
        self::assertSame($titler($fyrveien), $titler($sok($nummer . ' byggesak')));
        // Dokumentene i mappen: avsenderen på et brev finner mappen, og treffet viser dokumentet.
        $part = $this->grunndata->saker()[0]['journalposter'][0]['part'];
        $treff = $sok($part);
        self::assertNotSame([], $treff);
        self::assertNotSame([], self::rader($treff[0]['journalposter']));
        // Ingen treff, og for korte søk.
        self::assertSame([], $sok('finnesikkeiarkivet'));
        self::assertSame([], $sok('elevmappe finnesikke'));
        self::assertSame([], $sok('e'));
        self::assertSame([], $sok(''));
        // En mappe der selve mappen har ordet, kommer før en nyere mappe som bare har det i dokumentene. Et treff
        // viser høyst tre av dokumentene.
        $eldre = $this->nyMappe($this->kari, ['tittel' => 'Leie av sjøbodkaien – avtale', 'arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK']);
        $nyere = $this->nyMappe($this->kari, ['tittel' => 'Havnevesenet – diverse henvendelser', 'arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK']);
        foreach (range(1, 4) as $nr) {
            [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$nyere/journalposter", ['journalfores' => true, 'type' => 'X', 'tittel' => "Notat $nr om sjøbodkaien", 'dokumentdato' => '2026-11-20', 'part_navn' => '', 'tilgangskode' => '']);
            self::assertSame(201, $svar->status, $svar->kropp);
        }
        $kaien = $sok('sjøbodkaien');
        self::assertSame([$eldre, $nyere], array_map(static fn (array $t): string => Felt::tekst(self::rader([$t['sak']])[0]['id']), $kaien));
        self::assertSame([], $kaien[0]['journalposter'], 'Mappen har ordet selv, og ingen dokumenter.');
        self::assertSame(['Notat 1 om sjøbodkaien', 'Notat 2 om sjøbodkaien', 'Notat 3 om sjøbodkaien'], array_column(self::rader($kaien[1]['journalposter']), 'tittel'));
        // Søket går bare i brukerens eget arkiv.
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        $this->nyMappe($ola, ['tittel' => 'Høring – særegen sak hos Ola', 'arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK']);
        self::assertSame([], $sok('særegen'));
    }

    public function testMappenOpprettesForstOgFaarJournalposterEtterpaa(): void
    {
        // Fra saksmalen for elevmapper: forslaget til tittel må fylles ut.
        $leah = $this->registerkode('ELEV', 'Lerke, Leah');
        $mappe = ['mal' => 'elevmappe', 'tittel' => 'Elevmappe – [etternavn, fornavn]', 'arkivdel' => 'ELEV', 'klasse' => $leah, 'enhet' => 'OPP',
            'skjerm_tittel' => true, 'offentlig_tittel' => 'Elevmappe – *****', 'skjerm_klasse' => true, 'tilgangskode' => '13-1'];
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/saker', $mappe);
        self::assertSame(422, $svar->status);
        self::assertSame('tittel_ikke_utfylt', $j['kode']);
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/saker', ['tilgangskode' => ''] + $mappe);
        self::assertSame(422, $svar->status);
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/saker', ['tittel' => 'Elevmappe – Lerke, Leah', 'tilgangskode' => '', 'skjerm_tittel' => false] + $mappe);
        self::assertSame('skjerming_uten_hjemmel', $j['kode'], 'Skjermet klassering må også ha en hjemmel.');
        $sakId = $this->nyMappe($this->kari, ['tittel' => 'Elevmappe – Lerke, Leah'] + $mappe);

        // Mappen står tom til studenten registrerer noe i den.
        [$svar, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['sak']);
        self::assertSame([], $j['journalposter']);
        self::assertTrue($j['kan_slettes']);
        self::assertFalse($j['sak']['fulgte_med']);
        self::assertSame('elevmappe', $j['sak']['mal']);
        self::assertSame('2026/' . ($this->urort()['saker'] + 1), $j['sak']['saksnummer']);
        self::assertSame('Elevarkiv', $j['sak']['arkivdel_tekst']);
        self::assertSame($leah, $j['sak']['klasse_tekst']);
        self::assertStringStartsWith('28.06.2017 Lerke, Leah', $leah);
        self::assertTrue($j['sak']['skjerm_klasse']);
        self::assertTrue($j['sak']['skjerm_tittel']);
        self::assertSame('2026-11-20', $j['sak']['saksdato']);
        self::assertSame('Rakel Rådyr', $j['sak']['saksansvarlig_navn'], 'Saksansvarlig er den første i enheten.');
        [, $o] = $this->kari->kall('GET', '/api/arkiv');
        self::assertIsArray($o['oversikt']);
        self::assertSame($this->urort()['saker'] + 1, $o['oversikt']['saker']);
        self::assertSame($this->urort()['journalposter'], $o['oversikt']['journalposter']);

        // Flere journalposter i samme mappe: studentens egne registreringer, uten dokument fra postmottaket.
        $post = ['journalfores' => true, 'type' => 'U', 'tittel' => 'Vedtak om skoleplass – Leah Lerke', 'offentlig_tittel' => 'Vedtak om skoleplass – *****', 'dokumentdato' => '2026-11-20',
            'part_navn' => 'Nina og Pål Lerke', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true];
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", $post);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertNull($j['oppgave']);
        self::assertNull($j['poeng']);
        self::assertIsArray($j['journalpost']);
        self::assertSame(1, $j['journalpost']['postnr']);
        self::assertTrue($j['journalpost']['kan_slettes']);
        self::assertFalse($j['journalpost']['har_dokument']);
        $forste = Felt::tekst($j['journalpost']['id']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['type' => 'X', 'tittel' => 'Notat fra møte med foresatte', 'part_navn' => ''] + $post);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(2, $j['journalpost']['postnr']);
        // En egen registrering som arkiveres uten journalføring, beholder typen studenten valgte, og må ha en.
        $uten = ['journalfores' => false, 'type' => 'X', 'tittel' => 'Huskelapp til mappen', 'part_navn' => '', 'tilgangskode' => '', 'skjerm_tittel' => false, 'skjerm_part' => false];
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['type' => ''] + $uten + $post);
        self::assertSame(422, $svar->status);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", $uten + $post);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame('X', $j['journalpost']['type']);
        self::assertFalse($j['journalpost']['journalfort']);
        self::assertSame(3, $j['journalpost']['postnr']);
        $this->kari->kall('DELETE', '/api/arkiv/journalposter/' . Felt::tekst($j['journalpost']['id']));
        [, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertCount(2, self::rader($j['journalposter']));
        self::assertFalse($j['kan_slettes'], 'En mappe med dokumenter kan ikke slettes.');
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/saker/$sakId");
        self::assertSame(409, $svar->status);
        self::assertSame('mappe_ikke_tom', $j['kode']);
        // Ugyldige journalposter oppretter ingenting.
        $ugyldige = [['tittel' => ' '], ['dokumentdato' => '2026-11-21'], ['type' => 'Z'], ['tilgangskode' => '99'], ['tilgangskode' => ''], ['part_navn' => ''], ['oppgave' => 'finnes-ikke'],
            ['offentlig_tittel' => str_repeat('a', 501)]];
        foreach ($ugyldige as $nr => $endring) {
            [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", $endring + $post);
            self::assertSame(422, $svar->status, "Ugyldig journalpost nr. $nr: " . $svar->kropp);
        }
        [$svar] = $this->kari->kall('POST', '/api/arkiv/saker/finnes-ikke/journalposter', $post);
        self::assertSame(404, $svar->status);

        // Egne registreringer kan slettes, og da kan den tomme mappen slettes.
        [$svar] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$forste");
        self::assertSame(200, $svar->status, $svar->kropp);
        [, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        $igjen = self::rader($j['journalposter']);
        self::assertCount(1, $igjen);
        $this->kari->kall('DELETE', '/api/arkiv/journalposter/' . Felt::tekst($igjen[0]['id']));
        [$svar] = $this->kari->kall('DELETE', "/api/arkiv/saker/$sakId");
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertSame(404, $svar->status);
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertSame($this->urort(), $j['oversikt']);

        // Hendelsene står i loggen med nummeret til mappen eller journalposten, uten titler og navn.
        $logg = [];
        foreach ($this->db->alle("SELECT hva, hendelse FROM bivrost_logg WHERE hva LIKE 'urn:arkivar:hendelse:mappe:%' OR hva LIKE 'urn:arkivar:hendelse:journalpost:%'") as $rad) {
            $logg[Felt::tekst($rad['hva'])][] = Felt::tekst($rad['hendelse']);
        }
        self::assertEqualsCanonicalizing(
            ['urn:arkivar:hendelse:mappe:opprettet', 'urn:arkivar:hendelse:mappe:slettet', 'urn:arkivar:hendelse:journalpost:registrert', 'urn:arkivar:hendelse:journalpost:slettet'],
            array_keys($logg),
        );
        self::assertCount(3, $logg['urn:arkivar:hendelse:journalpost:registrert']);
        self::assertCount(3, $logg['urn:arkivar:hendelse:journalpost:slettet']);
        $saksnummer = '"2026/' . ($this->urort()['saker'] + 1) . '"';
        self::assertStringContainsString($saksnummer, $logg['urn:arkivar:hendelse:mappe:opprettet'][0]);
        self::assertStringContainsString($saksnummer, $logg['urn:arkivar:hendelse:mappe:slettet'][0]);
        // Løpenummeret teller alle registreringene, også dem som er arkivert uten journalføring.
        $forsteLopenummer = '"2026/' . (count(array_merge(...array_column($this->grunndata->saker(), 'journalposter'))) + 1) . '"';
        self::assertStringContainsString($forsteLopenummer, $logg['urn:arkivar:hendelse:journalpost:registrert'][0]);
        self::assertStringContainsString($forsteLopenummer, implode(' ', $logg['urn:arkivar:hendelse:journalpost:slettet']), 'Den slettede journalposten står med løpenummeret sitt.');
        $alt = implode(' ', array_merge(...array_values($logg)));
        foreach (['Lerke', 'Leah', 'Vedtak om skoleplass', 'Notat fra møte'] as $ord) {
            self::assertStringNotContainsString($ord, $alt, 'Loggen har ikke titler og navn fra arkivet.');
        }

        // Mappene og dokumentene som fulgte med, kan ikke slettes, og en avsluttet mappe tar ikke imot nye dokumenter.
        $fyrveien = $this->sakId($this->kari, 'Byggesak – tilbygg');
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/saker/$fyrveien");
        self::assertSame(409, $svar->status);
        self::assertSame('mappe_fulgte_med', $j['kode']);
        [, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$fyrveien");
        [$svar, $j] = $this->kari->kall('DELETE', '/api/arkiv/journalposter/' . Felt::tekst(self::rader($j['journalposter'])[0]['id']));
        self::assertSame(409, $svar->status);
        self::assertSame('journalpost_fulgte_med', $j['kode']);
        $avsluttet = $this->sakId($this->kari, 'Byggesak – garasje – Strandveien 16');
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$avsluttet/journalposter", $post);
        self::assertSame(409, $svar->status);
        self::assertSame('mappe_avsluttet', $j['kode']);
    }

    public function testDenOffentligeJournalenRoperIngenNavnFraPersonarkivene(): void
    {
        [, $j] = $this->kari->kall('GET', '/api/arkiv/saker');
        $saker = array_column(self::rader($j['saker']), null, 'id');
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/journal?offentlig=1');
        self::assertSame(200, $svar->status, $svar->kropp);
        $sett = 0;
        foreach (self::rader($j['journalposter']) as $rad) {
            $sak = $saker[Felt::tekst($rad['sak_id'])];
            $del = $this->grunndata->arkivdel(Felt::tekst($sak['arkivdel']));
            if ($del === null || $del['ordning'] !== Grunndata::PERSON) {
                self::assertSame($sak['klasse_tekst'], $rad['klasse_tekst'], 'Klasseringen vises når den ikke er skjermet.');
                continue;
            }
            $sett++;
            self::assertNotSame('', Felt::tekst($rad['hjemmel']), 'Skjermingen har en hjemmel.');
            self::assertSame('*****', $rad['klasse_tekst'], 'Klasseringen (fødselsdato og navn) er skjermet.');
            $vises = Felt::tekst($rad['tittel']) . ' ' . Felt::tekst($rad['sakstittel']) . ' ' . Felt::tekst($rad['part_navn']);
            foreach ($this->grunndata->register($del['kode']) as $post) {
                if ($post['kode'] !== $sak['klasse']) {
                    continue;
                }
                $deler = preg_split('/[,\s]+/', $post['tittel'], -1, PREG_SPLIT_NO_EMPTY);
                foreach ($deler === false ? [] : $deler as $navn) {
                    self::assertDoesNotMatchRegularExpression('/(?<![\p{L}])' . preg_quote($navn, '/') . '(?![\p{L}])/iu', $vises, "Navnet $navn vises i den offentlige journalen.");
                }
                self::assertStringNotContainsString((string) $post['fnr'], $svar->kropp, 'Fødselsnummeret står ikke i journalen.');
            }
            self::assertStringNotContainsString(Felt::tekst($sak['klasse']), $svar->kropp, 'Klasseringen står ikke i den offentlige journalen.');
        }
        self::assertGreaterThan(10, $sett, 'Personarkivene har journalposter fra start.');
        // Journalen for arkivtjenesten viser klasseringen.
        [$svar] = $this->kari->kall('GET', '/api/arkiv/journal');
        self::assertStringContainsString($this->registerkode('ELEV', 'Måke, Mathias'), $svar->kropp);
    }

    public function testPostmottaketViserDokumenteneUtenFasit(): void
    {
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak');
        $dokumenter = self::rader($j['dokumenter']);
        self::assertCount(count($this->oppgaver->alle()), $dokumenter);
        self::assertNotContains(true, array_column($dokumenter, 'behandlet'));
        $mottatt = array_column($dokumenter, 'mottatt');
        $stigende = $mottatt;
        sort($stigende);
        self::assertSame($stigende, $mottatt, 'Eldste mottatt først.');

        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/tilrettelegging-elev');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertFalse($j['behandlet']);
        self::assertIsArray($j['dokument']);
        self::assertSame('Marit og Rune Måke', self::rader([$j['dokument']['avsender']])[0]['navn']);
        self::assertStringNotContainsString('fasit', $svar->kropp);
        self::assertStringNotContainsString('forklaring', $svar->kropp);
        self::assertStringNotContainsString('13-1', $svar->kropp, 'Ingenting fra fasiten følger med før dokumentet er behandlet.');
        self::assertStringNotContainsString('14.03.2016', $svar->kropp, 'Klasseringen fra fasiten følger ikke med.');

        // Et brev som viser til kommunens saksnummer, får nummeret mappen har i dette arkivet.
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/tilsynsrapport-nasjonalarkivet');
        self::assertStringNotContainsString('{{', $svar->kropp);
        [, $s] = $this->kari->kall('GET', '/api/arkiv/saker/' . $this->sakId($this->kari, 'Tilsyn med dokumentasjonsforvaltningen'));
        self::assertIsArray($s['sak']);
        self::assertStringContainsString('Deres ref. ' . Felt::tekst($s['sak']['saksnummer']), $svar->kropp);

        [$svar] = $this->kari->kall('GET', '/api/arkiv/postmottak/finnes-ikke');
        self::assertSame(404, $svar->status);
    }

    public function testFasitenGirFullUttellingForAlleDokumentene(): void
    {
        $nye = 0;
        foreach ($this->oppgaver->alle() as $oppgave) {
            $j = $this->folgFasiten($this->kari, $oppgave);
            $feil = array_column(array_filter(self::rader($j['vurdering']), static fn (array $k): bool => $k['ok'] !== true), 'id');
            self::assertSame([], $feil, $oppgave['id'] . ': fasiten skal gi riktig på alle kriteriene.');
            self::assertSame($j['maks'], $j['poeng'], $oppgave['id']);
            $nye += ($oppgave['fasit']['sak']['type'] ?? '') === 'ny' ? 1 : 0;
        }
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertIsArray($j['oversikt']);
        self::assertSame(count($this->oppgaver->alle()), $j['oversikt']['behandlet']);
        self::assertSame($j['oversikt']['maks'], $j['oversikt']['poeng']);
        self::assertSame($this->urort()['saker'] + $nye, $j['oversikt']['saker'], 'Hvert dokument som starter en ny sak, har fått en mappe.');
    }

    public function testJournalforingISkjermetSakOgOffentligJournal(): void
    {
        // En ny elev: mappen opprettes i elevarkivet med fødselsdato og navn som primærklassering, alt skjermet.
        $leah = $this->registerkode('ELEV', 'Lerke, Leah');
        $sakId = $this->nyMappe($this->kari, ['mal' => 'elevmappe', 'tittel' => 'Elevmappe – Lerke, Leah', 'arkivdel' => 'ELEV', 'klasse' => $leah, 'enhet' => 'OPP',
            'skjerm_tittel' => true, 'offentlig_tittel' => 'Elevmappe – *****', 'skjerm_klasse' => true, 'tilgangskode' => '13-1']);
        $j = $this->registrer($this->kari, $sakId, 'innmelding-lerke', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Innmelding av ny elev – Leah Lerke',
            'offentlig_tittel' => 'Innmelding av ny elev – *****', 'dokumentdato' => '2026-11-13', 'part_navn' => 'Nina og Pål Lerke', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true]);
        self::assertSame($j['maks'], $j['poeng'], json_encode($j['vurdering'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertContains('skjerm_klasse', array_column(self::rader($j['vurdering']), 'id'), 'Skjermingen av klasseringen på en ny mappe vurderes.');
        self::assertIsArray($j['sak']);
        self::assertSame($sakId, $j['sak']['id']);
        self::assertIsArray($j['journalpost']);
        self::assertSame('J', $j['journalpost']['status']);
        self::assertSame('2026-11-20', $j['journalpost']['journaldato']);
        self::assertTrue($j['journalpost']['har_dokument']);
        self::assertSame('innmelding-lerke', $j['journalpost']['oppgave']);
        self::assertFalse($j['journalpost']['kan_slettes'], 'En registrering fra postmottaket fjernes ved å angre.');
        [$svar, $feil] = $this->kari->kall('DELETE', '/api/arkiv/journalposter/' . Felt::tekst($j['journalpost']['id']));
        self::assertSame(409, $svar->status);
        self::assertSame('journalpost_fra_postmottak', $feil['kode']);

        // Journalen for arkivtjenesten viser alt; den offentlige journalen skjermer tittelen, klasseringen og avsenderen.
        [, $j] = $this->kari->kall('GET', '/api/arkiv/journal');
        $forst = self::rader($j['journalposter'])[0];
        self::assertSame('Innmelding av ny elev – Leah Lerke', $forst['tittel']);
        self::assertSame('Nina og Pål Lerke', $forst['part_navn']);
        self::assertSame($leah, $forst['klasse_tekst']);
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/journal?offentlig=1');
        $forst = self::rader($j['journalposter'])[0];
        self::assertSame('Innmelding av ny elev – *****', $forst['tittel']);
        self::assertSame('*****', $forst['part_navn']);
        self::assertSame('Elevmappe – *****', $forst['sakstittel']);
        self::assertSame('*****', $forst['klasse_tekst']);
        self::assertStringContainsString('Offl. § 13 første ledd', Felt::tekst($forst['hjemmel']));
        self::assertStringNotContainsString('Lerke', $svar->kropp, 'Navnet står ingen steder i den offentlige journalen.');
        // En åpen journalpost i den skjermede mappen: journalen viser hjemmelen for skjermingen på mappen.
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['journalfores' => true, 'type' => 'X', 'tittel' => 'Notat om skolestart', 'dokumentdato' => '2026-11-20', 'part_navn' => '', 'tilgangskode' => '']);
        self::assertSame(201, $svar->status, $svar->kropp);
        [, $j] = $this->kari->kall('GET', '/api/arkiv/journal?offentlig=1');
        $notat = self::rader($j['journalposter'])[0];
        self::assertSame('Notat om skolestart', $notat['tittel']);
        self::assertNull($notat['tilgangskode']);
        self::assertSame('Elevmappe – *****', $notat['sakstittel']);
        self::assertStringContainsString('Offl. § 13 første ledd', Felt::tekst($notat['sak_hjemmel']), 'Skjermingen på mappen har hjemmelen sin i journalen.');
        $fyrveien = array_values(array_filter(self::rader($j['journalposter']), static fn (array $p): bool => str_contains(Felt::tekst($p['sakstittel']), 'Fyrveien 8')))[0];
        self::assertSame('', $fyrveien['sak_hjemmel'], 'En åpen mappe har ingen hjemmel.');

        // Dokumentet til journalposten fra postmottaket kan åpnes fra mappen, uten fasiten. En egen registrering har ikke noe dokument.
        [, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        $poster = array_column(self::rader($j['journalposter']), null, 'tittel');
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/journalposter/' . Felt::tekst($poster['Innmelding av ny elev – Leah Lerke']['id']));
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['dokument']);
        $innmelding = $this->oppgaver->hent('innmelding-lerke');
        self::assertNotNull($innmelding);
        self::assertSame($innmelding['emne'], $j['dokument']['emne']);
        self::assertSame($innmelding['tekst'], $j['dokument']['tekst']);
        self::assertStringNotContainsString('fasit', $svar->kropp);
        self::assertStringNotContainsString('forklaring', $svar->kropp);
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/journalposter/' . Felt::tekst($poster['Notat om skolestart']['id']));
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertNull($j['dokument']);
        self::assertIsArray($j['journalpost']);
        self::assertFalse($j['journalpost']['har_dokument']);

        // En elev som har mappe fra før: søknaden legges i den.
        $oppgave = $this->oppgaver->hent('tilrettelegging-elev');
        self::assertNotNull($oppgave);
        $j = $this->folgFasiten($this->kari, $oppgave);
        self::assertSame($j['maks'], $j['poeng']);
        self::assertIsArray($j['sak']);
        self::assertTrue($j['sak']['fulgte_med']);
        self::assertSame('Elevmappe – Måke, Mathias', $j['sak']['tittel']);
        self::assertNotContains('skjerm_klasse', array_column(self::rader($j['vurdering']), 'id'), 'Klasseringen på en mappe som finnes, er ikke studentens valg.');
    }

    public function testFeilValgGirForklaringOgNavnIOffentligTittelOppdages(): void
    {
        // Klagen legges i en ny mappe i sakarkivet, og tittelen skjermes uten grunn.
        $sakId = $this->nyMappe($this->kari, ['tittel' => 'Klage fra nabo', 'arkivdel' => 'SAK', 'klasse' => '512', 'enhet' => 'PBE']);
        $j = $this->registrer($this->kari, $sakId, 'klage-fyrveien', ['journalfores' => true, 'type' => 'U', 'tittel' => 'Klage', 'dokumentdato' => '2026-11-16', 'part_navn' => 'Solveig Mæland',
            'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => false]);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertTrue($vurdering['handling']['ok']);
        foreach (['sak', 'arkivdel', 'klasse', 'dokumentdato', 'type', 'tilgang', 'skjerm_tittel'] as $id) {
            self::assertFalse($vurdering[$id]['ok'], $id);
        }
        self::assertTrue($vurdering['part']['ok']);
        self::assertTrue($vurdering['skjerm_part']['ok']);
        self::assertTrue($vurdering['skjerm_klasse']['ok'], 'Mappen er studentens egen, og klasseringen i sakarkivet står riktig åpent.');
        self::assertArrayNotHasKey('skjerm_sakstittel', $vurdering, 'Fasiten er en mappe som finnes, så tittelen på den nye vurderes ikke.');
        self::assertStringContainsString('samme mappen', Felt::tekst($vurdering['sak']['forklaring']));
        self::assertSame('ny', $vurdering['sak']['ditt']);
        self::assertSame('eksisterende', $vurdering['sak']['riktig']);
        self::assertSame('Sakarkiv', $vurdering['arkivdel']['ditt']);
        self::assertSame('Eiendomsarkiv', $vurdering['arkivdel']['riktig']);
        self::assertSame('512 Reguleringsplaner', $vurdering['klasse']['ditt']);
        self::assertSame('9/112 Fyrveien 8', $vurdering['klasse']['riktig']);
        self::assertSame(4, $j['poeng']);
        self::assertSame(11, $j['maks']);
        // Forklaringen om skjerming står én gang, ved kriteriet som avviker.
        self::assertStringContainsString('Ingenting skal skjermes', Felt::tekst($vurdering['skjerm_tittel']['forklaring']));
        self::assertSame('', $vurdering['skjerm_part']['forklaring']);
        self::assertIsArray($j['forslag']);
        self::assertStringContainsString(' Byggesak – tilbygg til bolig', Felt::tekst($j['forslag']['sak']));

        // Varselet legges i samme mappe som klagen: en ny sak skal ha sin egen mappe, ikke en samlemappe.
        $j = $this->registrer($this->kari, $sakId, 'varsel-arbeidsmiljo', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Varsel om avdelingsleder Geir Gaupe', 'dokumentdato' => '2026-11-14',
            'part_navn' => 'Hilde Hegre', 'tilgangskode' => '14', 'skjerm_tittel' => false, 'skjerm_part' => true]);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertFalse($vurdering['sak']['ok'], 'Mappen har alt et dokument som hører til en annen sak.');
        self::assertStringStartsWith('2026/', Felt::tekst($vurdering['sak']['ditt']));
        self::assertFalse($vurdering['klasse']['ok']);
        self::assertTrue($vurdering['skjerm_klasse']['ok'], 'Klasseringen i sakarkivet skal ikke skjermes.');
        self::assertTrue($vurdering['skjerm_sakstittel']['ok'], 'Tittelen på mappen står åpent.');
        self::assertFalse($vurdering['offentlig_tittel']['ok'], 'Den offentlige tittelen røper et navn.');
        self::assertTrue($vurdering['skjerm_tittel']['ok'], 'Tittelen røper et navn, så det er ikke skjermingen som mangler grunn.');
        self::assertTrue($vurdering['skjerm_part']['ok']);
        self::assertTrue($vurdering['tilgang']['ok'], 'Unntaket for organinterne dokumenter godtas for et varsel fra en ansatt.');

        // Eleven har en mappe fra før. En ny mappe med navn i tittelen og uten skjerming røper navnet og klasseringen.
        $mathias = $this->registerkode('ELEV', 'Måke, Mathias');
        $elevmappe = $this->nyMappe($this->kari, ['tittel' => 'Elevmappe – Mathias Måke', 'arkivdel' => 'ELEV', 'klasse' => $mathias, 'enhet' => 'OPP']);
        $j = $this->registrer($this->kari, $elevmappe, 'tilrettelegging-elev', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Søknad om tilrettelagt opplæring – Mathias Måke',
            'offentlig_tittel' => 'Søknad om tilrettelagt opplæring – *****', 'dokumentdato' => '2026-11-10', 'part_navn' => 'Marit og Rune Måke', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true]);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertFalse($vurdering['sak']['ok'], 'Eleven har en mappe fra før.');
        self::assertTrue($vurdering['klasse']['ok']);
        self::assertFalse($vurdering['skjerm_klasse']['ok'], 'Fødselsdato og navn står åpent på den nye mappen.');
        self::assertSame('ja', $vurdering['skjerm_klasse']['riktig']);
        self::assertFalse($vurdering['offentlig_sakstittel']['ok'], 'Sakstittelen røper navnet.');
        self::assertTrue($vurdering['offentlig_tittel']['ok']);

        // En tittel der dyrenavnet bare er en del av et annet ord, røper ikke navnet («måkeskrik» er ikke «Måke»).
        $this->kari->kall('DELETE', '/api/arkiv/postmottak/tilrettelegging-elev');
        $j = $this->registrer($this->kari, $elevmappe, 'tilrettelegging-elev', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Søknad om tilrettelagt opplæring – måkeskrik og Måkes sak',
            'offentlig_tittel' => 'Søknad om tilrettelagt opplæring og måkeskrik', 'dokumentdato' => '2026-11-10', 'part_navn' => 'Marit og Rune Måke', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true]);
        self::assertTrue(array_column(self::rader($j['vurdering']), null, 'id')['offentlig_tittel']['ok']);
        $this->kari->kall('DELETE', '/api/arkiv/postmottak/tilrettelegging-elev');
        $j = $this->registrer($this->kari, $elevmappe, 'tilrettelegging-elev', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Søknad om tilrettelagt opplæring – Måkes sak',
            'dokumentdato' => '2026-11-10', 'part_navn' => 'Marit og Rune Måke', 'tilgangskode' => '13-1', 'skjerm_tittel' => false, 'skjerm_part' => true]);
        self::assertFalse(array_column(self::rader($j['vurdering']), null, 'id')['offentlig_tittel']['ok'], 'Navnet i genitiv røper også eleven.');

        // Innsynskravet skal arkiveres, men er ikke omfattet av journalføringsplikten.
        $innsyn = $this->nyMappe($this->kari, ['mal' => 'innsyn', 'tittel' => 'Innsyn – reiseregninger 2026 – Jærposten', 'arkivdel' => 'SAK', 'klasse' => '047', 'enhet' => 'DOK']);
        $j = $this->registrer($this->kari, $innsyn, 'innsynskrav-jaerposten', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Krav om innsyn i reiseregninger', 'dokumentdato' => '2026-11-18',
            'part_navn' => 'Jærposten', 'tilgangskode' => '']);
        self::assertSame(0, $j['poeng']);
        self::assertSame(1, $j['maks'], 'Når behandlingen er feil, vurderes ikke resten.');
        self::assertStringContainsString('arkivforskrifta § 14 tredje ledd', Felt::tekst(self::rader($j['vurdering'])[0]['forklaring']));
        $this->kari->kall('DELETE', '/api/arkiv/postmottak/innsynskrav-jaerposten');
        $j = $this->registrer($this->kari, $innsyn, 'innsynskrav-jaerposten', ['journalfores' => false, 'tittel' => 'Krav om innsyn i reiseregninger', 'dokumentdato' => '2026-11-18', 'part_navn' => 'Jærposten']);
        self::assertSame($j['maks'], $j['poeng'], json_encode($j['vurdering'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertIsArray($j['journalpost']);
        self::assertFalse($j['journalpost']['journalfort']);

        // Reklamen journalføres: bare behandlingen vurderes. Og et dokument som skal i arkivet, kan ikke bare få valget registrert.
        $kurs = $this->nyMappe($this->kari, ['tittel' => 'Kurs i dokumenthåndtering', 'arkivdel' => 'SAK', 'klasse' => '274', 'enhet' => 'DOK']);
        $j = $this->registrer($this->kari, $kurs, 'kursinvitasjon', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Invitasjon til kurs', 'dokumentdato' => '2026-11-16', 'part_navn' => 'Nordisk Kurs og Kompetanse AS', 'tilgangskode' => '']);
        self::assertSame(0, $j['poeng']);
        self::assertSame(1, $j['maks']);
        self::assertStringContainsString('reklame', Felt::tekst(self::rader($j['vurdering'])[0]['forklaring']));
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/horing-verneplan', ['handling' => 'journalfor']);
        self::assertSame(422, $svar->status);
        self::assertSame('krever_mappe', $j['kode']);
        [$svar] = $this->kari->kall('POST', '/api/arkiv/postmottak/horing-verneplan', ['handling' => 'kast']);
        self::assertSame(422, $svar->status);
    }

    public function testVurderingenFolgerMedNaarMappenEndres(): void
    {
        // Høringen får sin egen mappe: alt riktig.
        $horing = $this->oppgaver->hent('horing-verneplan');
        self::assertNotNull($horing);
        $j = $this->folgFasiten($this->kari, $horing);
        self::assertSame($j['maks'], $j['poeng']);
        self::assertIsArray($j['sak']);
        $sakId = Felt::tekst($j['sak']['id']);
        $maks = Felt::tall($j['maks']);

        // Innkallingen til representantskapet legges i samme mappe. Da er mappen en samlemappe, og den er feil for
        // begge dokumentene, også det som ble registrert først.
        $j = $this->registrer($this->kari, $sakId, 'representantskap-renovasjon', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Innkalling til representantskapsmøte 08.12.2026',
            'dokumentdato' => '2026-11-17', 'part_navn' => 'Jæren Renovasjon', 'tilgangskode' => '']);
        $ny = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertFalse($ny['sak']['ok']);
        self::assertTrue($ny['part']['ok'], 'Selskapsformen (IKS) trenger ikke stå i avsenderen.');
        $forste = $this->vurdering($this->kari, 'horing-verneplan');
        self::assertFalse($forste['kriterier']['sak']['ok'], 'Dokumentet deler nå mappen med en annen sak.');
        self::assertStringStartsWith('2026/', Felt::tekst($forste['kriterier']['sak']['ditt']));
        self::assertSame([$maks - 1, $maks], [$forste['poeng'], $forste['maks']]);
        [, $o] = $this->kari->kall('GET', '/api/arkiv');
        self::assertIsArray($o['oversikt']);
        self::assertSame($forste['poeng'] + Felt::tall($j['poeng']), $o['oversikt']['poeng'], 'Poengene på forsiden følger vurderingen.');

        // Innkallingen angres: høringen er alene i mappen igjen, og vurderingen er som før.
        [$svar] = $this->kari->kall('DELETE', '/api/arkiv/postmottak/representantskap-renovasjon');
        self::assertSame(200, $svar->status, $svar->kropp);
        $forste = $this->vurdering($this->kari, 'horing-verneplan');
        self::assertTrue($forste['kriterier']['sak']['ok']);
        self::assertSame('ny', $forste['kriterier']['sak']['ditt']);
        self::assertSame([$maks, $maks], [$forste['poeng'], $forste['maks']]);

        // En egen registrering i mappen er ikke en annen sak fra postmottaket, og endrer ingenting.
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['journalfores' => true, 'type' => 'X', 'tittel' => 'Notat til saken', 'dokumentdato' => '2026-11-20', 'part_navn' => '', 'tilgangskode' => '']);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertTrue($this->vurdering($this->kari, 'horing-verneplan')['kriterier']['sak']['ok']);
    }

    public function testSkjermingUtenGrunnGirAvvik(): void
    {
        // Mappen til høringen skjermes uten grunn: tittelen røper ingenting, og klasseringen er en klasse i arkivnøkkelen.
        $sakId = $this->nyMappe($this->kari, ['tittel' => 'Høring – utvidelse av Ognasanden landskapsvernområde', 'arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK',
            'skjerm_tittel' => true, 'offentlig_tittel' => 'Høring – *****', 'skjerm_klasse' => true, 'tilgangskode' => '13-1']);
        $post = ['journalfores' => true, 'type' => 'I', 'tittel' => 'Høring – utvidelse av Ognasanden landskapsvernområde', 'dokumentdato' => '2026-11-09', 'part_navn' => 'Statsforvalteren', 'tilgangskode' => ''];
        $j = $this->registrer($this->kari, $sakId, 'horing-verneplan', $post);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertTrue($vurdering['sak']['ok']);
        self::assertTrue($vurdering['part']['ok'], 'Kortformen av avsenderen godtas.');
        self::assertFalse($vurdering['skjerm_klasse']['ok']);
        self::assertSame(['ja', 'nei'], [$vurdering['skjerm_klasse']['ditt'], $vurdering['skjerm_klasse']['riktig']]);
        self::assertFalse($vurdering['skjerm_sakstittel']['ok']);
        self::assertTrue($vurdering['skjerm_tittel']['ok']);
        self::assertSame(Felt::tall($j['maks']) - 2, $j['poeng']);
        // Forklaringen om skjerming står ved det første avviket.
        self::assertStringContainsString('Ingenting skal skjermes', Felt::tekst($vurdering['skjerm_klasse']['forklaring']));
        self::assertSame('', $vurdering['skjerm_sakstittel']['forklaring']);

        // Varselet: tittelen skal stå åpent når den ikke nevner navn. Skjermet uten navn i tittelen er et avvik;
        // skjermet fordi studenten skrev navnet i tittelen, er riktig.
        $varsel = $this->nyMappe($this->kari, ['tittel' => 'Varsling – arbeidsmiljøet ved Sirevåg omsorgssenter', 'arkivdel' => 'SAK', 'klasse' => '256', 'enhet' => 'HR']);
        $post = ['journalfores' => true, 'type' => 'I', 'tittel' => 'Varsel om arbeidsmiljøet ved omsorgssenteret', 'offentlig_tittel' => 'Varsel', 'dokumentdato' => '2026-11-14',
            'part_navn' => 'Hegre, Hilde', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true];
        $j = $this->registrer($this->kari, $varsel, 'varsel-arbeidsmiljo', $post);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertFalse($vurdering['skjerm_tittel']['ok'], 'Tittelen røper ingenting og skal stå åpent.');
        self::assertTrue($vurdering['offentlig_tittel']['ok']);
        self::assertTrue($vurdering['part']['ok'], 'Etternavnet først er samme navn.');
        $this->kari->kall('DELETE', '/api/arkiv/postmottak/varsel-arbeidsmiljo');
        $j = $this->registrer($this->kari, $varsel, 'varsel-arbeidsmiljo', ['tittel' => 'Varsel fra Hilde Hegre om arbeidsmiljøet', 'offentlig_tittel' => 'Varsel om arbeidsmiljøet ved omsorgssenteret'] + $post);
        $vurdering = array_column(self::rader($j['vurdering']), null, 'id');
        self::assertTrue($vurdering['skjerm_tittel']['ok'], 'Navnet står i tittelen, så den må skjermes.');
        self::assertTrue($vurdering['offentlig_tittel']['ok']);
        self::assertSame($j['maks'], $j['poeng'], json_encode($j['vurdering'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function testRegistreringerISammeArkivGaarEtterHverandre(): void
    {
        // To forespørsler i samme arkiv samtidig (to faner, dobbel innsending): den andre venter på den første.
        // Her er de to forespørslene to databaseforbindelser, og den andre gir opp etter ett sekund.
        $this->kari->kall('GET', '/api/arkiv');
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        $ola->kall('GET', '/api/arkiv');
        $karis = Felt::tekst($this->db->verdi('SELECT a.id FROM arkivar_arkiv a JOIN arkivar_bruker b ON b.id = a.eier_id WHERE b.epost = ?', ['kari@hogskolen.example']));
        $olas = Felt::tekst($this->db->verdi('SELECT a.id FROM arkivar_arkiv a JOIN arkivar_bruker b ON b.id = a.eier_id WHERE b.epost = ?', ['ola@hogskolen.example']));
        self::assertNotSame($karis, $olas);
        $annen = TestDb::kobleTil($this->dbNavn);
        $annen->utfor('SET SESSION innodb_lock_wait_timeout = 1');
        $forste = new Register($this->db, $this->klokke, $this->grunndata);
        $andre = new Register($annen, $this->klokke, $this->grunndata);
        $ventet = $this->db->transaksjon(function () use ($forste, $andre, $annen, $karis, $olas): bool {
            $forste->laas($karis);
            // Et annet arkiv er ikke låst.
            $annen->transaksjon(static function () use ($andre, $olas): void {
                $andre->laas($olas);
            });
            try {
                $annen->transaksjon(static function () use ($andre, $karis): void {
                    $andre->laas($karis);
                });
            } catch (\Throwable $e) {
                return str_contains($e->getMessage(), '1205');
            }

            return false;
        });
        self::assertTrue($ventet, 'Den andre forespørselen venter på låsen (og ga her opp med «Lock wait timeout»).');
        // Når den første er ferdig, slipper den andre til.
        $annen->transaksjon(static function () use ($andre, $karis): void {
            $andre->laas($karis);
        });

        // Alt som registrerer, angrer, sletter eller nullstiller i arkivet, tar låsen: mens den holdes av en annen,
        // venter hver av operasjonene (og gir her opp), og ingenting er endret etterpå.
        $fyrveien = $this->sakId($this->kari, 'Byggesak – tilbygg');
        $tom = $this->nyMappe($this->kari, ['tittel' => 'Tom mappe som kan slettes', 'arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK']);
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$tom/journalposter", ['journalfores' => true, 'type' => 'X', 'tittel' => 'Notat som kan slettes', 'dokumentdato' => '2026-11-20', 'part_navn' => '', 'tilgangskode' => '']);
        self::assertIsArray($j['journalpost']);
        $notat = Felt::tekst($j['journalpost']['id']);
        $this->kari->kall('POST', '/api/arkiv/postmottak/kursinvitasjon', ['handling' => 'ikke_arkiver']);
        $postmottak = new Postmottak($annen, $this->klokke, $this->grunndata, $this->oppgaver, $andre);
        $mapper = new Mapper($annen, $this->klokke, $this->grunndata, $andre);
        $ovingsarkiv = new Ovingsarkiv($annen, $this->klokke, $this->grunndata, $andre, $this->oppgaver);
        $journalpost = ['oppgave' => 'klage-fyrveien', 'journalfores' => true, 'type' => 'I', 'tittel' => 'Klage på vedtak', 'dokumentdato' => '2026-11-17', 'part_navn' => 'Solveig Mæland', 'tilgangskode' => ''];
        $operasjoner = [
            'registrer' => static fn (): mixed => $postmottak->registrer($karis, 'faktura-ror', ['handling' => 'fagsystem']),
            'journalpost' => static fn (): mixed => $postmottak->journalpost($karis, $fyrveien, $journalpost),
            'angre' => static fn () => $postmottak->angre($karis, 'kursinvitasjon'),
            'slettJournalpost' => static fn (): mixed => $postmottak->slettJournalpost($karis, $notat),
            'opprett' => static fn (): mixed => $mapper->opprett($karis, ['tittel' => 'Mappe bak låsen', 'arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK']),
            'slett' => static fn () => $mapper->slett($karis, $tom),
            'nullstill' => static fn () => $ovingsarkiv->nullstill($karis),
        ];
        $for = [
            Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_sak WHERE arkiv_id = ?', [$karis])),
            Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_journalpost WHERE arkiv_id = ?', [$karis])),
            Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_oppgavestatus WHERE arkiv_id = ?', [$karis])),
        ];
        $fullfort = $this->db->transaksjon(static function () use ($forste, $karis, $operasjoner): array {
            $forste->laas($karis);
            $fullfort = [];
            foreach ($operasjoner as $navn => $operasjon) {
                try {
                    $operasjon();
                    $fullfort[] = $navn;
                } catch (\PDOException $e) {
                    if (!str_contains($e->getMessage(), '1205')) {
                        throw $e;
                    }
                }
            }

            return $fullfort;
        });
        self::assertSame([], $fullfort, 'Disse operasjonene ventet ikke på låsen på arkivet.');
        self::assertSame($for, [
            Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_sak WHERE arkiv_id = ?', [$karis])),
            Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_journalpost WHERE arkiv_id = ?', [$karis])),
            Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_oppgavestatus WHERE arkiv_id = ?', [$karis])),
        ]);
        // Samme dokument kan ikke registreres to ganger: sjekken står bak låsen.
        $sakId = $this->nyMappe($this->kari, ['tittel' => 'Byggesak – garasje – Strandveien 14', 'arkivdel' => 'EIENDOM', 'klasse' => '12/45', 'enhet' => 'PBE']);
        $post = ['oppgave' => 'dispensasjon-strandveien', 'journalfores' => true, 'type' => 'I', 'tittel' => 'Søknad om dispensasjon', 'dokumentdato' => '2026-11-12', 'part_navn' => 'Gudmund Refsnes', 'tilgangskode' => ''];
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", $post);
        self::assertSame(201, $svar->status, $svar->kropp);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", $post);
        self::assertSame(409, $svar->status);
        self::assertSame('alt_behandlet', $j['kode']);
        self::assertSame(1, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_journalpost WHERE sak_id = ?', [$sakId])));
    }

    public function testDokumentSomErTattUtAvPostmottaketKanRyddesBort(): void
    {
        // Innholdet er byttet siden arkivet ble fylt, og et dokument studenten har registrert, finnes ikke lenger i
        // postmottaket. Her etterliknes det ved å gi registreringen en ID som ikke finnes.
        $horing = $this->oppgaver->hent('horing-verneplan');
        self::assertNotNull($horing);
        $j = $this->folgFasiten($this->kari, $horing);
        self::assertIsArray($j['sak']);
        self::assertIsArray($j['journalpost']);
        $sakId = Felt::tekst($j['sak']['id']);
        $journalpostId = Felt::tekst($j['journalpost']['id']);
        $this->kari->kall('POST', '/api/arkiv/postmottak/kursinvitasjon', ['handling' => 'ikke_arkiver']);
        $this->db->utfor("UPDATE arkivar_journalpost SET oppgave_id = 'tatt-ut' WHERE id = ?", [$journalpostId]);
        $this->db->utfor("UPDATE arkivar_oppgavestatus SET oppgave_id = 'tatt-ut' WHERE oppgave_id = 'horing-verneplan'");

        // Behandlingen teller ikke lenger, verken på forsiden eller i lærerens oversikt.
        [, $o] = $this->kari->kall('GET', '/api/arkiv');
        self::assertIsArray($o['oversikt']);
        self::assertSame([1, 1, 1], [$o['oversikt']['behandlet'], $o['oversikt']['poeng'], $o['oversikt']['maks']]);
        $laerer = $this->ansatt($this->drift(), 'laerer', 'lars@hogskolen.example', 'Lars Lærer', '10.0.0.9');
        [, $k] = $laerer->kall('POST', '/api/klasser', ['navn' => 'Arkivkunnskap høst']);
        self::assertIsArray($k['klasse']);
        $this->kari->kall('POST', '/api/meg/klasser', ['kode' => Felt::tekst($k['klasse']['kode'])]);
        [, $k] = $laerer->kall('GET', '/api/klasser/' . Felt::tekst($k['klasse']['id']));
        $student = self::rader($k['studenter'])[0];
        self::assertSame([1, 1, 1], [$student['behandlet'], $student['poeng'], $student['maks']]);

        // Journalposten har ikke noe dokument å vise og ingen behandling å angre: den kan slettes.
        [, $s] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        $post = self::rader($s['journalposter'])[0];
        self::assertSame([null, false, true], [$post['oppgave'], $post['har_dokument'], $post['kan_slettes']]);
        [$svar, $jp] = $this->kari->kall('GET', "/api/arkiv/journalposter/$journalpostId");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertNull($jp['dokument']);
        // Den gjør ikke mappen feil for et annet dokument: innkallingen registrert i mappen er alene om den.
        $ny = $this->registrer($this->kari, $sakId, 'representantskap-renovasjon', ['journalfores' => true, 'type' => 'I', 'tittel' => 'Innkalling til representantskapsmøte 08.12.2026',
            'dokumentdato' => '2026-11-17', 'part_navn' => 'Jæren Renovasjon IKS', 'tilgangskode' => '']);
        self::assertTrue(array_column(self::rader($ny['vurdering']), null, 'id')['sak']['ok']);
        [$svar] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$journalpostId");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(0, Felt::tall($this->db->verdi("SELECT COUNT(*) FROM arkivar_oppgavestatus WHERE oppgave_id = 'tatt-ut'")), 'Behandlingen er ryddet bort med journalposten.');
        [, $s] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertCount(1, self::rader($s['journalposter']));
    }

    public function testMappeskjemaetValideres(): void
    {
        $gyldig = ['tittel' => 'Byggesak – garasje – Strandveien 14', 'arkivdel' => 'EIENDOM', 'klasse' => '12/45', 'enhet' => 'PBE'];
        $ugyldige = [
            ['tittel' => 'ab'],
            ['tittel' => 'Byggesak – [tiltak] – Strandveien 14'],
            ['mal' => 'finnes-ikke'],
            // Klasseringen må høre til arkivdelens ordning: gårds- og bruksnummer i eiendomsarkivet, arkivnøkkelen i sakarkivet.
            ['klasse' => '512'],
            ['klasse' => '99/999'],
            ['klasse' => ''],
            ['arkivdel' => 'SAK'],
            ['arkivdel' => 'SAK', 'klasse' => '5'],
            ['arkivdel' => 'SAK', 'klasse' => '51'],
            // 221 Personalmapper viser til personalarkivet og kan ikke brukes på en mappe i sakarkivet.
            ['arkivdel' => 'SAK', 'klasse' => '221'],
            ['arkivdel' => 'PERSONAL'],
            // Fødselsnummeret er ikke klasseringen: det er fødselsdato og navn.
            ['arkivdel' => 'ELEV', 'klasse' => (string) $this->grunndata->register('ELEV')[0]['fnr']],
            ['arkivdel' => 'XXX'],
            ['enhet' => 'XX'],
            ['tilgangskode' => '99'],
            ['skjerm_tittel' => true],
            ['skjerm_klasse' => true],
            // En for lang offentlig tittel avvises, den kuttes ikke.
            ['skjerm_tittel' => true, 'tilgangskode' => '13-1', 'offentlig_tittel' => str_repeat('a', 501)],
        ];
        foreach ($ugyldige as $nr => $endring) {
            [$svar] = $this->kari->kall('POST', '/api/arkiv/saker', $endring + $gyldig);
            self::assertSame(422, $svar->status, "Ugyldig mappe nr. $nr: " . $svar->kropp);
        }
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertSame($this->urort(), $j['oversikt'], 'Et skjema med feil oppretter ingen mappe.');
        $sakId = $this->nyMappe($this->kari, $gyldig);

        $post = ['oppgave' => 'dispensasjon-strandveien', 'journalfores' => true, 'type' => 'I', 'tittel' => 'Søknad om dispensasjon', 'dokumentdato' => '2026-11-12', 'part_navn' => 'Gudmund Refsnes', 'tilgangskode' => ''];
        $feil = ['skjerming_uten_hjemmel' => ['skjerm_tittel' => true], 'ugyldig_dato' => ['dokumentdato' => '2026-11-21']];
        foreach ($feil as $kode => $endring) {
            [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", $endring + $post);
            self::assertSame(422, $svar->status, $kode);
            self::assertSame($kode, $j['kode']);
        }
        [, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertFalse($j['behandlet'], 'Et skjema med feil behandler ikke dokumentet.');
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", $post);
        self::assertSame(201, $svar->status, $svar->kropp);
    }

    public function testLaererenSerFremdriftenTilStudenteneIKlassen(): void
    {
        $laerer = $this->ansatt($this->drift(), 'laerer', 'lars@hogskolen.example', 'Lars Lærer', '10.0.0.9');
        [, $j] = $laerer->kall('POST', '/api/klasser', ['navn' => 'Arkivkunnskap høst']);
        self::assertIsArray($j['klasse']);
        $klasseId = Felt::tekst($j['klasse']['id']);
        [$svar] = $this->kari->kall('POST', '/api/meg/klasser', ['kode' => Felt::tekst($j['klasse']['kode'])]);
        self::assertSame(200, $svar->status, $svar->kropp);
        $this->student('ola@hogskolen.example', 'Ola Student', Felt::tekst($j['klasse']['kode']), '10.0.0.2');

        // Kari behandler to dokumenter: ett riktig og ett feil. Ola har ikke åpnet arkivet.
        $this->kari->kall('POST', '/api/arkiv/postmottak/kursinvitasjon', ['handling' => 'ikke_arkiver']);
        $this->kari->kall('POST', '/api/arkiv/postmottak/faktura-ror', ['handling' => 'ikke_arkiver']);
        [$svar, $j] = $laerer->kall('GET', "/api/klasser/$klasseId");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(count($this->oppgaver->alle()), $j['dokumenter']);
        $studenter = array_column(self::rader($j['studenter']), null, 'navn');
        self::assertSame([2, 1, 2], [$studenter['Kari Student']['behandlet'], $studenter['Kari Student']['poeng'], $studenter['Kari Student']['maks']]);
        self::assertSame([0, 0, 0], [$studenter['Ola Student']['behandlet'], $studenter['Ola Student']['poeng'], $studenter['Ola Student']['maks']]);
    }

    public function testAngreNullstillOgAdskilteArkiver(): void
    {
        $sakId = $this->nyMappe($this->kari, ['mal' => 'byggesak', 'tittel' => 'Byggesak – garasje – Strandveien 14', 'arkivdel' => 'EIENDOM', 'klasse' => '12/45', 'enhet' => 'PBE']);
        $post = ['journalfores' => true, 'type' => 'I', 'tittel' => 'Søknad om dispensasjon', 'dokumentdato' => '2026-11-12', 'part_navn' => 'Gudmund Refsnes', 'tilgangskode' => ''];
        $this->registrer($this->kari, $sakId, 'dispensasjon-strandveien', $post);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['oppgave' => 'dispensasjon-strandveien'] + $post);
        self::assertSame(409, $svar->status);
        self::assertSame('alt_behandlet', $j['kode']);
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/postmottak/dispensasjon-strandveien', ['handling' => 'ikke_arkiver']);
        self::assertSame(409, $svar->status);

        // En annen student har sitt eget arkiv og ser ikke Karis mappe.
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        [, $j] = $ola->kall('GET', '/api/arkiv');
        self::assertSame($this->urort(), $j['oversikt']);
        [$svar] = $ola->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertSame(404, $svar->status);
        [$svar] = $ola->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['oppgave' => 'klage-fyrveien'] + $post);
        self::assertSame(404, $svar->status, 'En mappe i et annet arkiv kan ikke brukes.');
        [$svar] = $ola->kall('DELETE', "/api/arkiv/saker/$sakId");
        self::assertSame(404, $svar->status);
        // Det samme gjelder journalpostene: Ola kan verken lese eller slette Karis, heller ikke når ID-en er kjent.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['type' => 'X', 'tittel' => 'Notat til byggesaken', 'part_navn' => ''] + $post);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        $egen = Felt::tekst($j['journalpost']['id']);
        [, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertIsArray($j['journalpost']);
        $fraPostmottak = Felt::tekst($j['journalpost']['id']);
        foreach ([$egen, $fraPostmottak] as $journalpostId) {
            [$svar] = $ola->kall('GET', "/api/arkiv/journalposter/$journalpostId");
            self::assertSame(404, $svar->status);
            [$svar] = $ola->kall('DELETE', "/api/arkiv/journalposter/$journalpostId");
            self::assertSame(404, $svar->status);
            [$svar] = $this->kari->kall('GET', "/api/arkiv/journalposter/$journalpostId");
            self::assertSame(200, $svar->status, 'Journalposten står fortsatt hos Kari.');
        }
        [$svar] = $ola->kall('DELETE', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertSame(200, $svar->status, 'Ola har ikke behandlet dokumentet, så det er ingenting å angre.');
        [, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertTrue($j['behandlet'], 'Karis behandling står.');
        [$svar] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$egen");
        self::assertSame(200, $svar->status, $svar->kropp);

        // Angre: journalposten forsvinner, mappen blir stående tom, og dokumentet kan behandles på nytt.
        [$svar] = $this->kari->kall('DELETE', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId");
        self::assertSame(200, $svar->status);
        self::assertSame([], $j['journalposter']);
        self::assertTrue($j['kan_slettes']);
        [, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/dispensasjon-strandveien');
        self::assertFalse($j['behandlet']);
        $j = $this->registrer($this->kari, $sakId, 'dispensasjon-strandveien', $post);
        self::assertSame($j['maks'], $j['poeng'], json_encode($j['vurdering'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        // Angre i en mappe som fulgte med: mappen blir stående med dokumentene den hadde.
        $fyrveien = $this->sakId($this->kari, 'Byggesak – tilbygg');
        [, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$fyrveien");
        $for = count(self::rader($j['journalposter']));
        $this->registrer($this->kari, $fyrveien, 'klage-fyrveien', ['tittel' => 'Klage på vedtak', 'dokumentdato' => '2026-11-17', 'part_navn' => 'Solveig Mæland'] + $post);
        $this->kari->kall('DELETE', '/api/arkiv/postmottak/klage-fyrveien');
        [, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$fyrveien");
        self::assertCount($for, self::rader($j['journalposter']));

        // Arkivet husker utgaven av innholdet det ble fylt med. Er datafilene byttet siden, sier forsiden fra.
        $this->db->utfor("UPDATE arkivar_arkiv SET innhold = 'eldre-utgave'");
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertIsArray($j['oversikt']);
        self::assertTrue($j['oversikt']['utdatert']);

        // Nullstilling: arkivet er som da studenten fikk det, med innholdet som gjelder nå.
        [$svar] = $this->kari->kall('POST', '/api/arkiv/nullstill', []);
        self::assertSame(200, $svar->status, $svar->kropp);
        [, $j] = $this->kari->kall('GET', '/api/arkiv');
        self::assertSame($this->urort(), $j['oversikt']);

        // Driftsbrukeren og den som ikke er logget inn, har ikke noe arkiv.
        [$svar] = $this->drift()->kall('GET', '/api/arkiv');
        self::assertSame(403, $svar->status);
        $ukjent = $this->nettleser('10.0.0.9');
        $ukjent->start();
        foreach ([['GET', '/api/arkiv/postmottak'], ['GET', '/api/arkiv/sok?q=elevmappe'], ['POST', '/api/arkiv/saker']] as [$metode, $sti]) {
            [$svar] = $ukjent->kall($metode, $sti, []);
            self::assertSame(401, $svar->status, $sti);
        }
    }
}
