<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Enhet;

use ArkIvar\Arkiv\Dokumentfiler;
use ArkIvar\Arkiv\Kodelister;
use PHPUnit\Framework\TestCase;

/**
 * Dokumentfilene til en journalpost: formatet følger av hvordan dokumentet ble til, og arkivformatet (PDF eller ren
 * tekst) finnes bare når journalposten er arkivert.
 */
final class DokumentfilerTest extends TestCase
{
    public function testProduksjonsformatetFolgerAvHvordanDokumentetBleTil(): void
    {
        self::assertSame('eml', Dokumentfiler::produksjonsformat('epost', '2026-11-10'));
        self::assertSame('eml', Dokumentfiler::produksjonsformat('epost', null));
        self::assertSame('tif', Dokumentfiler::produksjonsformat('brev', '2026-11-10'), 'Et brev på papir er skannet.');
        self::assertSame('tif', Dokumentfiler::produksjonsformat('skjema', '2026-11-10'));
        self::assertSame('pdf', Dokumentfiler::produksjonsformat('avtale', '2026-11-10'), 'Andre dokumenter utenfra er mottatt som PDF.');
        self::assertSame('docx', Dokumentfiler::produksjonsformat('brev', null), 'Kommunens egne dokumenter er skrevet i tekstbehandler.');
        self::assertSame('docx', Dokumentfiler::produksjonsformat('saksframlegg', null));
    }

    public function testArkivformatetErPdfEllerTekst(): void
    {
        self::assertSame('txt', Dokumentfiler::arkivformat('epost'));
        foreach (['brev', 'skjema', 'notat', 'vedtak', 'protokoll', 'avtale'] as $kanal) {
            self::assertSame('pdf', Dokumentfiler::arkivformat($kanal));
            self::assertContains(Dokumentfiler::arkivformat($kanal), Dokumentfiler::ARKIVFORMATER);
        }
    }

    public function testArkivformatetKommerITilleggNaarJournalpostenErArkivert(): void
    {
        $journalfort = Dokumentfiler::filer('brev', '2026-11-10', Kodelister::JP_JOURNALFORT, 'Søknad om støtte');
        self::assertSame([['variantformat' => 'P', 'format' => 'tif', 'filnavn' => 'soknad-om-stotte.tif']], $journalfort);
        $arkivert = Dokumentfiler::filer('epost', '2026-11-10', Kodelister::JP_ARKIVERT, 'Spørsmål om åpningstider');
        self::assertSame(
            [
                ['variantformat' => 'P', 'format' => 'eml', 'filnavn' => 'sporsmal-om-apningstider.eml'],
                ['variantformat' => 'A', 'format' => 'txt', 'filnavn' => 'sporsmal-om-apningstider.txt'],
            ],
            $arkivert,
        );
    }

    public function testFilnavnetHarBareTryggeTegn(): void
    {
        self::assertSame('byggesak-garasje-strandveien-16', Dokumentfiler::filnavn('Byggesak – garasje – Strandveien 16'));
        self::assertSame('dokument', Dokumentfiler::filnavn(' – '));
        $langt = Dokumentfiler::filnavn(str_repeat('ord ', 40));
        self::assertLessThanOrEqual(60, strlen($langt));
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $langt);
    }
}
