<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Enhet;

use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Kodelister;
use ArkIvar\Arkiv\Oppgavesett;
use PHPUnit\Framework\TestCase;

/** Grunndataene og eksempeldokumentene henger sammen: alle koder som brukes, finnes. */
final class GrunndataTest extends TestCase
{
    public function testSakeneSomFolgerMedBrukerGyldigeKoder(): void
    {
        $g = new Grunndata();
        self::assertNotSame([], $g->saker());
        foreach ($g->saker() as $sak) {
            self::assertTrue($g->harArkivdel($sak['arkivdel']), $sak['nokkel']);
            self::assertTrue($g->kanKlassifiseres($sak['klasse']), $sak['nokkel']);
            self::assertTrue($g->harEnhet($sak['enhet']), $sak['nokkel']);
            self::assertNotNull($g->ansatt($sak['saksansvarlig']), $sak['nokkel']);
            foreach ($sak['journalposter'] as $post) {
                self::assertContains($post['type'], Kodelister::JOURNALPOSTTYPER, $post['tittel']);
                self::assertContains($post['status'], Kodelister::JOURNALSTATUSER, $post['tittel']);
                self::assertTrue($post['tilgangskode'] === null || $g->harTilgangskode($post['tilgangskode']), $post['tittel']);
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $post['dokumentdato']);
            }
        }
        foreach ($g->ansatte() as $ansatt) {
            self::assertTrue($g->harEnhet($ansatt['enhet']), $ansatt['navn']);
        }
        self::assertFalse($g->kanKlassifiseres('5'), 'En hovedgruppe kan ikke brukes på en sak.');
        self::assertSame('520 Byggesaker', $g->klassetekst('520'));
    }

    public function testFasitenForHvertDokumentBrukerGyldigeKoder(): void
    {
        $g = new Grunndata();
        $nokler = array_column($g->saker(), 'nokkel');
        $ider = [];
        foreach ((new Oppgavesett())->alle() as $o) {
            $fasit = $o['fasit'];
            self::assertMatchesRegularExpression('/^[a-z0-9-]{3,60}$/', $o['id']);
            self::assertNotContains($o['id'], $ider, 'ID-ene er unike.');
            $ider[] = $o['id'];
            self::assertNotSame([], $o['tekst'], $o['id']);
            self::assertLessThanOrEqual($o['mottatt'], $o['dokumentdato'], $o['id'] . ': dokumentet er datert før det ble mottatt.');
            self::assertContains($fasit['handling'], Kodelister::HANDLINGER, $o['id']);
            self::assertArrayHasKey('handling', $fasit['forklaring'], $o['id']);
            $arkiveres = in_array($fasit['handling'], [Kodelister::JOURNALFOR, Kodelister::ARKIVER], true);
            self::assertSame($arkiveres, $fasit['sak'] !== null, $o['id']);
            if ($fasit['sak'] === null) {
                continue;
            }
            self::assertTrue($g->harArkivdel($fasit['sak']['arkivdel']), $o['id']);
            self::assertNotSame([], $fasit['sak']['klasse'], $o['id']);
            foreach ($fasit['sak']['klasse'] as $klasse) {
                self::assertTrue($g->kanKlassifiseres($klasse), $o['id']);
            }
            if ($fasit['sak']['type'] === 'eksisterende') {
                self::assertContains($fasit['sak']['nokkel'], $nokler, $o['id']);
            } else {
                self::assertNotNull($fasit['sak']['tittel'], $o['id']);
            }
            self::assertNotNull($fasit['tittel'], $o['id']);
            foreach ($fasit['tilgangskode'] as $kode) {
                self::assertTrue($g->harTilgangskode($kode), $o['id']);
            }
            if ($fasit['handling'] === Kodelister::JOURNALFOR) {
                self::assertContains($fasit['type'], Kodelister::JOURNALPOSTTYPER, $o['id']);
                // Skjerming må ha en hjemmel.
                self::assertFalse(($fasit['skjerm_tittel'] === true || $fasit['skjerm_part'] === true) && $fasit['tilgangskode'] === [], $o['id']);
            }
        }
        self::assertGreaterThanOrEqual(10, count($ider));
    }
}
