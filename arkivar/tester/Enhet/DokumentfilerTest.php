<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Enhet;

use ArkIvar\Arkiv\Dokumentfiler;
use ArkIvar\Arkiv\Kodelister;
use PHPUnit\Framework\TestCase;

/**
 * Dokumentfilene til en journalpost: formatet følger av hvordan dokumentet ble til, og arkivformatet (PDF eller ren
 * tekst) finnes når journalposten er journalført, ekspedert eller arkivert.
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

    public function testArkivformatetKommerITilleggNaarJournalpostenErJournalfort(): void
    {
        $reservert = Dokumentfiler::filer('brev', '2026-11-10', 'R', 'Søknad om støtte');
        self::assertSame([['versjon' => 1, 'variantformat' => 'P', 'format' => 'tif', 'filnavn' => 'soknad-om-stotte.tif']], $reservert);
        foreach (Kodelister::MED_ARKIVFORMAT as $status) {
            self::assertSame(
                [
                    ['versjon' => 1, 'variantformat' => 'P', 'format' => 'eml', 'filnavn' => 'sporsmal-om-apningstider.eml'],
                    ['versjon' => 1, 'variantformat' => 'A', 'format' => 'txt', 'filnavn' => 'sporsmal-om-apningstider.txt'],
                ],
                Dokumentfiler::filer('epost', '2026-11-10', $status, 'Spørsmål om åpningstider'),
            );
        }
    }

    public function testJournalpostenHarHoveddokumentOgVedlegg(): void
    {
        $dokumenter = Dokumentfiler::dokumenter('epost', '2026-11-10', Kodelister::JP_JOURNALFORT, 'Spørsmål om åpningstider', ['Kart', 'Bilde av skiltet']);
        self::assertSame([1, 2, 3], array_column($dokumenter, 'nr'));
        self::assertSame(['H', 'V', 'V'], array_column($dokumenter, 'rolle'));
        self::assertSame(['Spørsmål om åpningstider', 'Kart', 'Bilde av skiltet'], array_column($dokumenter, 'tittel'));
        self::assertSame(['eml', 'txt'], array_column($dokumenter[0]['filer'], 'format'));
        self::assertSame(['pdf', 'pdf'], array_column($dokumenter[1]['filer'], 'format'), 'Et vedlegg til en e-post er en PDF, og arkiveres som PDF.');
        self::assertSame('kart.pdf', $dokumenter[1]['filer'][0]['filnavn']);
        // Vedlegg til et skannet brev er skannet, og vedlegg til kommunens egne dokumenter er skrevet i tekstbehandler.
        self::assertSame('tif', Dokumentfiler::vedleggsformat('brev', '2026-11-10'));
        self::assertSame('docx', Dokumentfiler::vedleggsformat('brev', null));
        self::assertCount(1, Dokumentfiler::dokumenter('notat', null, Kodelister::JP_JOURNALFORT, 'Notat', []));
    }

    public function testFilnavnetHarBareTryggeTegn(): void
    {
        self::assertSame('byggesak-garasje-strandveien-16', Dokumentfiler::filnavn('Byggesak – garasje – Strandveien 16'));
        self::assertSame('dokument', Dokumentfiler::filnavn(' – '));
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', Dokumentfiler::filnavn(str_repeat('æøå', 40) . ' – ' . str_repeat('日本', 20)));
        $langt = Dokumentfiler::filnavn(str_repeat('ord ', 40));
        self::assertLessThanOrEqual(60, strlen($langt));
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $langt);
    }
}
