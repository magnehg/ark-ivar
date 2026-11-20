<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Enhet;

use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Kodelister;
use ArkIvar\Arkiv\Oppgavesett;
use PHPUnit\Framework\TestCase;

/**
 * Grunndataene og eksempeldokumentene henger sammen: arkivdelene, arkivnøkkelen og registrene er riktig bygd opp,
 * alle koder som brukes i mappene og fasitene, finnes, og ingenting i personarkivene røper navn i offentlig journal.
 *
 * @phpstan-import-type Startsak from Grunndata
 */
final class GrunndataTest extends TestCase
{
    private const ARKIVDELER = ['SAK' => 'emne', 'EIENDOM' => 'eiendom', 'PERSONAL' => 'person', 'ELEV' => 'person', 'BARNEHAGE' => 'person', 'MOTE' => 'utvalg'];
    private const KANALER = ['brev', 'epost', 'skjema', 'notat', 'saksframlegg', 'protokoll', 'innkalling', 'avtale', 'vedtak'];
    private const I_DAG = '2026-11-20';

    public function testArkivdeleneHarHverSinOrdning(): void
    {
        $g = new Grunndata();
        self::assertSame(self::ARKIVDELER, array_column($g->arkivdeler(), 'ordning', 'kode'));
        foreach ($g->arkivdeler() as $del) {
            self::assertContains($del['ordning'], Grunndata::ORDNINGER);
            self::assertNotSame('', $del['mappe'], $del['kode']);
            self::assertNotSame('', $del['beskrivelse'], $del['kode']);
            // Sakarkivet bruker arkivnøkkelen, de andre arkivdelene har et register å klassifisere på.
            self::assertSame($del['ordning'] !== Grunndata::EMNE, $g->register($del['kode']) !== [], $del['kode']);
        }
    }

    public function testArkivnokkelenFolgerOppbygningenIFellesnokkelen(): void
    {
        $g = new Grunndata();
        $klasser = $g->klasser();
        $koder = array_column($klasser, 'kode');
        self::assertSame($koder, array_values(array_unique($koder)), 'Kodene er unike.');
        $titler = [];
        foreach ($klasser as $k) {
            $kode = $k['kode'];
            $titler['k' . $kode] = $k['tittel'];
            self::assertMatchesRegularExpression('/^\d{1,3}$/', $kode);
            self::assertSame(strlen($kode) === 1 ? null : substr($kode, 0, -1), $k['forelder'], "Klassen $kode ligger under klassen med ett siffer mindre.");
            self::assertTrue($k['forelder'] === null || in_array($k['forelder'], $koder, true), $kode);
            if ($k['henvisning'] !== null) {
                self::assertNotSame(Grunndata::EMNE, $g->arkivdel($k['henvisning'])['ordning'] ?? Grunndata::EMNE, "Klassen $kode viser til en arkivdel med egne mapper.");
                self::assertFalse($g->harKlasse('SAK', $kode), "Klassen $kode kan ikke brukes i sakarkivet.");
            }
            // 0 er «generelt» på hvert nivå, som i fellesnøkkelen.
            if (strlen($kode) > 1 && str_ends_with($kode, '0')) {
                self::assertStringStartsWith('Generel', $k['tittel'], $kode);
            }
        }
        // Klasse 0–2 er egenforvaltningen i fellesnøkkelen, 3–8 er kommunens fagområder.
        self::assertSame(['0', '1', '2', '3', '4', '5', '6', '7', '8'], array_values(array_filter($koder, static fn (string $k): bool => strlen($k) === 1)));
        self::assertSame('Organisasjon og administrasjon', $titler['k0']);
        self::assertSame('Økonomi', $titler['k1']);
        self::assertSame('Stillinger og personell', $titler['k2']);
        self::assertSame('Personalmapper', $titler['k221']);
        self::assertSame(['PERSONAL', 'ELEV', 'BARNEHAGE', 'EIENDOM'], array_values(array_filter(array_column($klasser, 'henvisning'), is_string(...))));

        self::assertTrue($g->harKlasse('SAK', '212'));
        self::assertFalse($g->harKlasse('SAK', '2'), 'En klasse kan ikke brukes på en mappe.');
        self::assertFalse($g->harKlasse('SAK', '21'), 'En hovedgruppe som er delt i grupper, kan ikke brukes på en mappe.');
        self::assertTrue($g->harKlasse('SAK', '02'), 'En hovedgruppe som ikke er delt videre, kan brukes.');
        self::assertFalse($g->harKlasse('SAK', '9/112'), 'Et gårds- og bruksnummer er ikke en klasse i arkivnøkkelen.');
        self::assertSame('212 Tilsetting', $g->klassetekst('SAK', '212'));
    }

    public function testRegistreneErOppdiktet(): void
    {
        $g = new Grunndata();
        foreach ($g->arkivdeler() as $del) {
            $koder = array_column($g->register($del['kode']), 'kode');
            self::assertSame($koder, array_unique($koder), $del['kode']);
            foreach ($g->register($del['kode']) as $post) {
                self::assertTrue($g->harKlasse($del['kode'], $post['kode']));
                self::assertNotSame('', $post['tittel']);
                if ($del['ordning'] === Grunndata::EIENDOM) {
                    self::assertMatchesRegularExpression('#^\d+/\d+$#', $post['kode']);
                }
                if ($del['ordning'] === Grunndata::PERSON) {
                    // Primærklasseringen er fødselsdato og navn. Fødselsnummeret står ved siden av og er oppdiktet.
                    self::assertMatchesRegularExpression('/^\p{Lu}[\p{L} -]*, \p{Lu}[\p{L} -]*$/u', $post['tittel'], 'Personer står som «Etternavn, Fornavn».');
                    $fodt = (string) $post['fodt'];
                    self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $fodt, $post['tittel']);
                    self::assertSame(substr($fodt, 8) . '.' . substr($fodt, 5, 2) . '.' . substr($fodt, 0, 4) . ' ' . $post['tittel'], $post['kode']);
                    $fnr = (string) $post['fnr'];
                    self::assertMatchesRegularExpression('/^\d{11}$/', $fnr, $post['tittel']);
                    self::assertSame(substr($fodt, 8) . substr($fodt, 5, 2) . substr($fodt, 2, 2), substr($fnr, 0, 6), $post['tittel'] . ': fødselsnummeret begynner med fødselsdatoen.');
                    self::assertFalse(self::gyldigFodselsnummer($fnr), $post['tittel'] . ' har et fødselsnummer som består kontrollen og kunne vært ekte.');
                    // Dyrenavn med bokstavrim: fornavnet og etternavnet begynner på samme bokstav.
                    [$etternavn, $fornavn] = explode(', ', $post['tittel']);
                    self::assertSame(mb_substr($etternavn, 0, 1, 'UTF-8'), mb_substr($fornavn, 0, 1, 'UTF-8'), $post['tittel']);
                } else {
                    self::assertNull($post['fnr'], $post['tittel']);
                }
            }
        }
        self::assertSame('9/112 Fyrveien 8', $g->klassetekst('EIENDOM', '9/112'));
        self::assertTrue(self::gyldigFodselsnummer('01010100050'), 'Kontrollen kjenner igjen et nummer med riktige kontrollsifre.');
        self::assertSame('KST Kommunestyret', $g->klassetekst('MOTE', 'KST'));
        $mathias = array_values(array_filter($g->register('ELEV'), static fn (array $p): bool => $p['tittel'] === 'Måke, Mathias'))[0];
        self::assertSame('14.03.2016 Måke, Mathias', $mathias['kode']);
        self::assertSame('14.03.2016 Måke, Mathias', $g->klassetekst('ELEV', $mathias['kode']), 'I personarkivene er klasseringen fødselsdato og navn.');
        // Alle ansatte som kan være saksansvarlige, har en plass i personalregisteret, og initialene er unike.
        $personal = array_column($g->register('PERSONAL'), 'tittel');
        $initialer = array_column($g->ansatte(), 'initialer');
        self::assertSame($initialer, array_values(array_unique($initialer)));
        foreach ($g->ansatte() as $ansatt) {
            self::assertTrue($g->harEnhet($ansatt['enhet']), $ansatt['navn']);
            $deler = explode(' ', $ansatt['navn']);
            $etternavn = array_pop($deler);
            self::assertContains($etternavn . ', ' . implode(' ', $deler), $personal, $ansatt['navn']);
            self::assertSame(mb_substr($etternavn, 0, 1, 'UTF-8'), mb_substr($ansatt['navn'], 0, 1, 'UTF-8'), $ansatt['navn'] . ' har dyrenavn med bokstavrim.');
        }
        self::assertContains('Gunnar Grevling', array_column($g->ansatte(), 'navn'));
        self::assertSame('PBE', $g->ansatt('GGR')['enhet'] ?? null, 'Gunnar Grevling arbeider med byggesak.');
    }

    public function testSaksmaleneForeslarArkivdelOgTittel(): void
    {
        $g = new Grunndata();
        $maler = $g->saksmaler();
        $koder = array_column($maler, 'kode');
        self::assertSame($koder, array_values(array_unique($koder)));
        foreach ($maler as $mal) {
            self::assertMatchesRegularExpression('/^[a-z]+$/', $mal['kode']);
            self::assertTrue($g->harArkivdel($mal['arkivdel']), $mal['kode']);
            self::assertTrue($g->harSaksmal($mal['kode']));
            foreach (['navn', 'beskrivelse', 'soketips'] as $felt) {
                self::assertNotSame('', $mal[$felt], $mal['kode'] . ' mangler ' . $felt);
            }
        }
        // Alle arkivdelene har minst én mal.
        self::assertSame([], array_diff(array_keys(self::ARKIVDELER), array_column($maler, 'arkivdel')));
        $elevmappe = array_column($maler, null, 'kode')['elevmappe'];
        self::assertSame('ELEV', $elevmappe['arkivdel']);
        self::assertSame('Elevmappe – [etternavn, fornavn]', $elevmappe['tittel'], 'Forslaget til tittel har hakeparenteser der studenten skriver inn.');
        self::assertStringContainsString('elevmappe', $elevmappe['soketips']);
        self::assertFalse($g->harSaksmal('finnes-ikke'));
    }

    public function testMappeneSomFolgerMedErHeleOgGyldige(): void
    {
        $g = new Grunndata();
        $nokler = [];
        $perArkivdel = [];
        $personer = [];
        foreach ($g->saker() as $sak) {
            $hvor = $sak['nokkel'];
            self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $hvor);
            self::assertLessThanOrEqual(60, strlen($hvor));
            self::assertNotContains($hvor, $nokler, 'Nøklene er unike.');
            $nokler[] = $hvor;
            self::assertTrue($g->harArkivdel($sak['arkivdel']), $hvor);
            self::assertTrue($g->harKlasse($sak['arkivdel'], $sak['klasse']), "$hvor: klassen {$sak['klasse']} kan ikke brukes i {$sak['arkivdel']}.");
            self::assertSame($sak['enhet'], $g->ansatt($sak['saksansvarlig'])['enhet'] ?? null, "$hvor: saksansvarlig hører til enheten.");
            self::assertContains($sak['status'], Kodelister::SAKSSTATUSER, $hvor);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $sak['saksdato'], $hvor);
            self::assertTrue($sak['tilgangskode'] === null || $g->harTilgangskode($sak['tilgangskode']), $hvor);
            $perArkivdel[$sak['arkivdel']] = ($perArkivdel[$sak['arkivdel']] ?? 0) + 1;
            $navn = [];
            if (self::ARKIVDELER[$sak['arkivdel']] === Grunndata::PERSON) {
                self::assertNotContains($sak['arkivdel'] . $sak['klasse'], $personer, "$hvor: én mappe per person.");
                $personer[] = $sak['arkivdel'] . $sak['klasse'];
                $navn = self::navn($g, $sak['arkivdel'], $sak['klasse']);
                self::assertNotNull($sak['tilgangskode'], "$hvor: mappene i personarkivene er skjermet.");
                self::assertNotNull($sak['offentlig_tittel'], $hvor);
                self::assertTrue($sak['skjerm_klasse'], "$hvor: klasseringen (fødselsdato og navn) er skjermet.");
                self::assertStringEndsWith('– ' . ($navn[0] ?? '') . ', ' . implode(' ', array_slice($navn, 1)), $sak['tittel'], "$hvor: tittelen slutter med navnet som i registeret.");
                self::assertSame([], self::ordI($sak['offentlig_tittel'], $navn), "$hvor: den offentlige tittelen røper navnet.");
            } else {
                self::assertFalse($sak['skjerm_klasse'], "$hvor: bare klasseringen i personarkivene er skjermet.");
            }
            self::assertNotSame([], $sak['journalposter'], $hvor);
            $forrige = '';
            foreach ($sak['journalposter'] as $post) {
                $her = "$hvor #{$post['nr']}";
                self::assertContains($post['type'], Kodelister::JOURNALPOSTTYPER, $her);
                self::assertContains($post['status'], Kodelister::JOURNALSTATUSER, $her);
                self::assertTrue($post['tilgangskode'] === null || $g->harTilgangskode($post['tilgangskode']), $her);
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $post['dokumentdato'], $her);
                self::assertLessThanOrEqual($post['journaldato'], $post['dokumentdato'], $her);
                self::assertLessThanOrEqual(self::I_DAG, $post['journaldato'], $her);
                self::assertGreaterThanOrEqual($forrige, $post['journaldato'], "$her: dokumentene står i den rekkefølgen de ble registrert.");
                $forrige = $post['journaldato'];
                self::assertFalse($post['tilgangskode'] === null && ($post['skjerm_part'] || $post['offentlig_tittel'] !== null), "$her: skjerming må ha en hjemmel.");
                if ($navn !== [] && $post['journalfort']) {
                    self::assertSame([], self::ordI($post['offentlig_tittel'] ?? $post['tittel'], $navn), "$her: tittelen i offentlig journal røper navnet.");
                    self::assertFalse(self::ordI($post['part'], [$navn[0]]) !== [] && !$post['skjerm_part'], "$her: parten har samme etternavn og må skjermes.");
                }
                // Alle dokumentene i arkivet kan åpnes og leses.
                $dok = $post['dokument'];
                self::assertNotNull($dok, "$her mangler dokument.");
                self::assertContains($dok['kanal'], self::KANALER, $her);
                self::assertNotSame('', $dok['emne'], $her);
                self::assertGreaterThanOrEqual(2, count($dok['tekst']), $her);
                self::assertNotSame('', $dok['avsender']['navn'], $her);
                if ($post['type'] === 'I') {
                    self::assertNotNull($dok['mottatt'], "$her: et inngående dokument har en mottaksdato.");
                    self::assertNull($dok['mottaker'], $her);
                    self::assertStringStartsNotWith('Sirevåg kommune', $dok['avsender']['navn'], $her);
                } else {
                    self::assertNull($dok['mottatt'], $her);
                    self::assertStringStartsWith('Sirevåg kommune', $dok['avsender']['navn'], "$her: kommunen har laget dokumentet.");
                    self::assertSame($post['type'] === 'U', $dok['mottaker'] !== null, "$her: bare utgående dokumenter har mottaker.");
                }
                self::assertSame($dok, $g->startdokument($hvor . '#' . $post['nr']));
            }
            self::assertSame([], self::utenforEksempeldomenet($sak), $hvor);
        }
        foreach (array_keys(self::ARKIVDELER) as $del) {
            self::assertGreaterThanOrEqual(2, $perArkivdel[$del] ?? 0, "Arkivdelen $del har mapper fra start.");
        }
        self::assertNull($g->startdokument('finnes-ikke#1'));
    }

    public function testFasitenForHvertDokumentBrukerGyldigeKoder(): void
    {
        $g = new Grunndata();
        $mapper = array_column($g->saker(), null, 'nokkel');
        $ider = [];
        $arkivdeler = [];
        $handlinger = [];
        foreach ((new Oppgavesett())->alle() as $o) {
            $fasit = $o['fasit'];
            $id = $o['id'];
            self::assertMatchesRegularExpression('/^[a-z0-9-]{3,60}$/', $id);
            self::assertNotContains($id, $ider, 'ID-ene er unike.');
            $ider[] = $id;
            self::assertGreaterThanOrEqual(2, count($o['tekst']), $id);
            self::assertLessThanOrEqual($o['mottatt'], $o['dokumentdato'], $id . ': dokumentet er datert før det ble mottatt.');
            self::assertLessThanOrEqual(self::I_DAG, $o['mottatt'], $id);
            self::assertSame([], self::utenforEksempeldomenet(Oppgavesett::dokument($o)), $id);
            self::assertContains($fasit['handling'], Kodelister::HANDLINGER, $id);
            self::assertArrayHasKey('handling', $fasit['forklaring'], $id);
            $handlinger[$fasit['handling']] = true;
            $arkiveres = in_array($fasit['handling'], [Kodelister::JOURNALFOR, Kodelister::ARKIVER], true);
            self::assertSame($arkiveres, $fasit['sak'] !== null, $id);
            $sak = $fasit['sak'];
            if ($sak === null) {
                continue;
            }
            $del = $sak['arkivdel'];
            self::assertTrue($g->harArkivdel($del), $id);
            $arkivdeler[$del] = true;
            self::assertNotSame([], $sak['klasse'], $id);
            foreach ($sak['klasse'] as $klasse) {
                self::assertTrue($g->harKlasse($del, $klasse), "$id: klassen $klasse kan ikke brukes i $del.");
            }
            foreach (['sak', 'arkivdel', 'klasse'] as $n) {
                self::assertArrayHasKey($n, $fasit['forklaring'], $id);
            }
            $person = self::ARKIVDELER[$del] === Grunndata::PERSON;
            $navn = $person ? self::navn($g, $del, $sak['klasse'][0]) : [];
            if ($sak['type'] === 'eksisterende') {
                $mappe = $mapper[$sak['nokkel']] ?? null;
                self::assertNotNull($mappe, "$id: mappen i fasiten finnes.");
                self::assertSame($del, $mappe['arkivdel'], $id);
                self::assertContains($mappe['klasse'], $sak['klasse'], $id);
                self::assertSame('B', $mappe['status'], "$id: mappen er ikke avsluttet.");
            } else {
                self::assertSame('ny', $sak['type'], $id);
                self::assertNotNull($sak['tittel'], $id);
                // Studenten oppretter mappen selv, og skjermingen av tittelen og klasseringen på den vurderes.
                self::assertArrayHasKey('skjerming', $fasit['forklaring'], "$id: en ny mappe har en forklaring om skjerming.");
                if ($person) {
                    // Hver person har én mappe: finnes den, skal dokumentet dit.
                    foreach ($mapper as $mappe) {
                        self::assertFalse($mappe['arkivdel'] === $del && in_array($mappe['klasse'], $sak['klasse'], true), "$id: personen har en mappe fra før.");
                    }
                    self::assertNotNull($sak['offentlig_tittel'], $id);
                    self::assertSame([], self::ordI($sak['offentlig_tittel'], $navn), $id);
                }
            }
            self::assertNotNull($fasit['tittel'], $id);
            foreach ($fasit['tilgangskode'] as $kode) {
                self::assertTrue($g->harTilgangskode($kode), $id);
            }
            if ($fasit['handling'] !== Kodelister::JOURNALFOR) {
                continue;
            }
            self::assertSame('I', $fasit['type'], "$id: dokumentene i postmottaket er inngående.");
            self::assertArrayHasKey('tilgang', $fasit['forklaring'], $id);
            self::assertArrayHasKey('skjerming', $fasit['forklaring'], $id);
            // Skjerming må ha en hjemmel. (null betyr at oppgaven ikke vurderer det.)
            self::assertFalse(($fasit['skjerm_tittel'] === true || $fasit['skjerm_part'] === true) && $fasit['tilgangskode'] === [], $id);
            if ($fasit['skjerm_tittel'] === true || $person) {
                self::assertNotSame([], $fasit['sensitive_ord'], $id);
            }
            if ($fasit['skjerm_tittel'] === true) {
                self::assertSame([], self::ordI((string) $fasit['offentlig_tittel'], $fasit['sensitive_ord']), "$id: den offentlige tittelen i fasiten røper et navn.");
            } else {
                self::assertSame([], self::ordI($fasit['tittel'], $fasit['sensitive_ord']), "$id: tittelen i fasiten røper et navn uten å være skjermet.");
            }
            $tekst = $o['emne'] . ' ' . implode(' ', $o['tekst']) . ' ' . $o['avsender']['navn'];
            foreach ($fasit['sensitive_ord'] as $ord) {
                self::assertNotSame([], self::ordI($tekst, [$ord]), "$id: det sensitive ordet $ord står ikke i dokumentet.");
            }
            foreach ($navn as $n) {
                self::assertNotSame([], self::ordI(implode(' ', $fasit['sensitive_ord']), [$n]), "$id: navnet $n mangler i de sensitive ordene.");
            }
        }
        self::assertGreaterThanOrEqual(15, count($ider));
        // Postmottaket øver på alle arkivdelene og alle behandlingsmåtene.
        self::assertSame([], array_diff(array_keys(self::ARKIVDELER), array_keys($arkivdeler)));
        self::assertSame([], array_diff(Kodelister::HANDLINGER, array_keys($handlinger)));
    }

    /**
     * Navnene til personen en mappe i et personarkiv gjelder, etternavnet først.
     *
     * @return list<string>
     */
    private static function navn(Grunndata $g, string $arkivdel, string $kode): array
    {
        foreach ($g->register($arkivdel) as $post) {
            if ($post['kode'] === $kode) {
                $deler = preg_split('/[,\s]+/', $post['tittel'], -1, PREG_SPLIT_NO_EMPTY);

                return $deler === false ? [] : $deler;
            }
        }

        return [];
    }

    /**
     * Ordene som står i teksten som egne ord (også med genitivs-s), uten hensyn til store bokstaver. Navnene er
     * korte dyrenavn, så det sammenliknes ord for ord.
     *
     * @param list<string> $ord
     * @return list<string>
     */
    private static function ordI(string $tekst, array $ord): array
    {
        $ordene = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($tekst, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $ordene = $ordene === false ? [] : $ordene;

        return array_values(array_filter($ord, static function (string $o) use ($ordene): bool {
            $o = mb_strtolower($o, 'UTF-8');

            return $o !== '' && (in_array($o, $ordene, true) || in_array($o . 's', $ordene, true));
        }));
    }

    /**
     * E-postadresser og nettadresser i innholdet som ikke ligger på et .example-domene.
     *
     * @param array<array-key, mixed> $innhold
     * @return list<string>
     */
    private static function utenforEksempeldomenet(array $innhold): array
    {
        $json = json_encode($innhold, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        preg_match_all('/[\w.+-]+@[\w.-]+|(?:https?:\/\/|www\.)[\w.\/-]+/u', $json, $treff);

        return array_values(array_filter($treff[0], static fn (string $adresse): bool => !str_contains($adresse, '.example')));
    }

    /** Om et fødselsnummer består kontrollen med de to kontrollsifrene (modulus 11). */
    private static function gyldigFodselsnummer(string $nummer): bool
    {
        $siffer = array_map(intval(...), str_split($nummer));

        return count($siffer) === 11
            && self::kontrollsiffer($siffer, [3, 7, 6, 1, 8, 9, 4, 5, 2]) === $siffer[9]
            && self::kontrollsiffer($siffer, [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]) === $siffer[10];
    }

    /**
     * Kontrollsifferet for de første sifrene med disse vektene. Gir 10 når kombinasjonen ikke kan brukes.
     *
     * @param list<int> $siffer
     * @param list<int> $vekter
     */
    private static function kontrollsiffer(array $siffer, array $vekter): int
    {
        $sum = 0;
        foreach ($vekter as $nr => $vekt) {
            $sum += $vekt * ($siffer[$nr] ?? 0);
        }
        $rest = 11 - $sum % 11;

        return $rest === 11 ? 0 : $rest;
    }
}
