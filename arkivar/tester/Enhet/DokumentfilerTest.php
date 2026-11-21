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
        // Sensitive henvendelser kommer ikke på e-post, men i kommunens digitale skjema eller gjennom SvarInn: begge gir en PDF.
        self::assertSame('pdf', Dokumentfiler::produksjonsformat('digitaltskjema', '2026-11-10'));
        self::assertSame('pdf', Dokumentfiler::produksjonsformat('svarinn', '2026-11-10'));
        self::assertSame('pdf', Dokumentfiler::arkivformat('digitaltskjema'));
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
                    ['versjon' => 1, 'variantformat' => 'A', 'format' => 'txt', 'filnavn' => 'sporsmal-om-apningstider.txt'],
                    ['versjon' => 1, 'variantformat' => 'P', 'format' => 'eml', 'filnavn' => 'sporsmal-om-apningstider.eml'],
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
        self::assertSame(['txt', 'eml'], array_column($dokumenter[0]['filer'], 'format'), 'Arkivformatet står først.');
        self::assertSame(['pdf', 'pdf'], array_column($dokumenter[1]['filer'], 'format'), 'Et vedlegg til en e-post er en PDF, og arkiveres som PDF.');
        self::assertSame('kart.pdf', $dokumenter[1]['filer'][0]['filnavn']);
        // Med et utkast har hoveddokumentet to versjoner: den ferdige først, og utkastet bare i produksjonsformat.
        $med = Dokumentfiler::dokumenter('brev', null, Kodelister::JP_EKSPEDERT, 'Svar på søknad', ['Kart'], 2);
        self::assertSame([[2, 'A', 'svar-pa-soknad.pdf'], [2, 'P', 'svar-pa-soknad.docx'], [1, 'P', 'svar-pa-soknad-v1.docx']], array_map(static fn (array $f): array => [$f['versjon'], $f['variantformat'], $f['filnavn']], $med[0]['filer']));
        self::assertSame([1, 1], array_column($med[1]['filer'], 'versjon'), 'Vedleggene har én versjon.');
        // Et dokument som er under arbeid (reservert), har tre versjoner og ingen arkivformat ennå.
        $utkast = Dokumentfiler::dokumenter('notat', null, Kodelister::JP_RESERVERT, 'Notat', [], 3);
        self::assertSame([[3, 'P', 'notat.docx'], [2, 'P', 'notat-v2.docx'], [1, 'P', 'notat-v1.docx']], array_map(static fn (array $f): array => [$f['versjon'], $f['variantformat'], $f['filnavn']], $utkast[0]['filer']));
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
