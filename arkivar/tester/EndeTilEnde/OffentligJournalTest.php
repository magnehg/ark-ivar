<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Tester\Stotte\AppTestCase;
use Bivrost\Kjerne\Database\Felt;

/**
 * Hjemmelen for skjermingen på mappen i den offentlige journalen (sak_hjemmel fra Register::visJournalpost). Den
 * skal stå der når noe på mappen er skjermet, tittelen eller klasseringen, også når journalposten selv er åpen. En
 * tilgangskode på mappen uten at noe er skjermet, gir ingen hjemmel i journalen: da er det ingenting å begrunne.
 */
final class OffentligJournalTest extends AppTestCase
{
    public function testHjemmelenPaaMappenFolgerDetSomErSkjermet(): void
    {
        $grunndata = new Grunndata();
        $this->klokke->sett('2026-11-20 09:00:00');
        $this->godkjennDomene();
        $kari = $this->student('kari@hogskolen.example');
        $felles = ['arkivdel' => 'SAK', 'klasse' => '641', 'enhet' => 'TEK'];
        $mapper = [
            'tittel' => ['tittel' => 'Mappe med skjermet tittel', 'skjerm_tittel' => true, 'offentlig_tittel' => 'Mappe med *****', 'tilgangskode' => '13-1'] + $felles,
            'klasse' => ['tittel' => 'Mappe med skjermet klassering', 'skjerm_klasse' => true, 'tilgangskode' => '13-2'] + $felles,
            'ingen' => ['tittel' => 'Mappe med tilgangskode uten skjerming', 'tilgangskode' => '13-1'] + $felles,
        ];
        $notat = [];
        foreach ($mapper as $navn => $mappe) {
            [$svar, $j] = $kari->kall('POST', '/api/arkiv/saker', $mappe);
            self::assertSame(201, $svar->status, $svar->kropp);
            self::assertIsArray($j['sak']);
            $sakId = Felt::tekst($j['sak']['id']);
            // En åpen journalpost: ingen tilgangskode og ingen skjerming på journalposten selv.
            [$svar, $j] = $kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['journalfores' => true, 'type' => 'X', 'tittel' => "Åpent notat ($navn)", 'dokumentdato' => '2026-11-20', 'part_navn' => '', 'tilgangskode' => '']);
            self::assertSame(201, $svar->status, $svar->kropp);
            self::assertIsArray($j['journalpost']);
            $notat[Felt::tekst($j['journalpost']['id'])] = $navn;
        }

        [$svar, $j] = $kari->kall('GET', '/api/arkiv/journal?offentlig=1');
        self::assertSame(200, $svar->status, $svar->kropp);
        $rekkefolge = [];
        $poster = ['tittel' => [], 'klasse' => [], 'ingen' => []];
        foreach (self::rader($j['journalposter']) as $post) {
            $navn = $notat[Felt::tekst($post['id'])] ?? null;
            if ($navn !== null) {
                $rekkefolge[] = $navn;
                $poster[$navn] = $post;
                self::assertSame("Åpent notat ($navn)", $post['tittel'], 'Journalposten selv er åpen.');
                self::assertNull($post['tilgangskode']);
                self::assertSame('', $post['hjemmel']);
            }
        }
        self::assertSame(['ingen', 'klasse', 'tittel'], $rekkefolge, 'Journalen har de tre notatene, nyeste først.');
        $klassetekst = $grunndata->klassetekst('SAK', '641');
        self::assertNotSame('', $grunndata->tilgangstekst('13-1'));
        self::assertNotSame($grunndata->tilgangstekst('13-1'), $grunndata->tilgangstekst('13-2'));

        // Bare tittelen er skjermet: den offentlige tittelen vises, klasseringen står åpent, og hjemmelen er mappens.
        self::assertSame('Mappe med *****', $poster['tittel']['sakstittel']);
        self::assertSame($klassetekst, $poster['tittel']['klasse_tekst']);
        self::assertTrue($poster['tittel']['sak_skjermet']);
        self::assertFalse($poster['tittel']['skjerm_klasse']);
        self::assertSame($grunndata->tilgangstekst('13-1'), $poster['tittel']['sak_hjemmel']);

        // Bare klasseringen er skjermet: tittelen står åpent, og hjemmelen for skjermingen må likevel stå i journalen.
        self::assertSame('Mappe med skjermet klassering', $poster['klasse']['sakstittel']);
        self::assertSame('*****', $poster['klasse']['klasse_tekst']);
        self::assertFalse($poster['klasse']['sak_skjermet']);
        self::assertTrue($poster['klasse']['skjerm_klasse']);
        self::assertSame($grunndata->tilgangstekst('13-2'), $poster['klasse']['sak_hjemmel']);

        // Tilgangskode uten skjerming: alt står åpent, og journalen har ingen hjemmel for mappen.
        self::assertSame('Mappe med tilgangskode uten skjerming', $poster['ingen']['sakstittel']);
        self::assertSame($klassetekst, $poster['ingen']['klasse_tekst']);
        self::assertFalse($poster['ingen']['sak_skjermet']);
        self::assertFalse($poster['ingen']['skjerm_klasse']);
        self::assertSame('', $poster['ingen']['sak_hjemmel']);
    }
}
