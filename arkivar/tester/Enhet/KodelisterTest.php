<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Enhet;

use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Kodelister;
use PHPUnit\Framework\TestCase;

/**
 * Kodelistene fra Noark 5: hver kode har et navn og en forklaring på alle språkene, og dokumentfilen får
 * variantformat etter statusen på journalposten.
 */
final class KodelisterTest extends TestCase
{
    /** Tekstnøklene for hver kodeliste (arkivar.<liste>.<kode> og arkivar.<liste>.<kode>.bruk). */
    private const LISTER = [
        'saksstatus' => Kodelister::SAKSSTATUSER,
        'jpstatus' => Kodelister::JOURNALSTATUSER,
        'dokstatus' => Kodelister::DOKUMENTSTATUSER,
        'variantformat' => Kodelister::VARIANTFORMATER,
        'avskrivningsmate' => Kodelister::AVSKRIVNINGSMATER,
    ];

    public function testHverStatuskodeHarNavnOgForklaringPaaAlleSpraak(): void
    {
        foreach (['nb', 'nn', 'en'] as $sprak) {
            $tekster = Grunndata::les(dirname(__DIR__, 2) . "/i18n/$sprak/grensesnitt.json");
            foreach (self::LISTER as $liste => $koder) {
                self::assertSame($koder, array_values(array_unique($koder)), "$liste: hver kode står én gang.");
                foreach ($koder as $kode) {
                    // Statuskodene er én stor bokstav. Avskrivningsmåtene har to eller tre (BU, TLF).
                    self::assertMatchesRegularExpression($liste === 'avskrivningsmate' ? '/^[A-Z]{2,3}$/' : '/^[A-Z]$/', $kode, "$liste: kodene er store bokstaver.");
                    $nokkel = "arkivar.$liste." . strtolower($kode);
                    foreach ([$nokkel, $nokkel . '.bruk'] as $n) {
                        self::assertIsString($tekster[$n] ?? null, "$sprak: $n mangler.");
                        self::assertNotSame('', trim($tekster[$n]), "$sprak: $n er tom.");
                    }
                }
            }
        }
    }

    public function testKodeneArkIvarSetterSelvStaarIListene(): void
    {
        self::assertContains(Kodelister::SAK_UNDER_BEHANDLING, Kodelister::SAKSSTATUSER);
        self::assertContains(Kodelister::SAK_AVSLUTTET, Kodelister::SAKSSTATUSER);
        self::assertContains(Kodelister::JP_JOURNALFORT, Kodelister::JOURNALSTATUSER);
        self::assertContains(Kodelister::JP_ARKIVERT, Kodelister::JOURNALSTATUSER);
        self::assertContains(Kodelister::DOK_FERDIGSTILT, Kodelister::DOKUMENTSTATUSER);
        self::assertContains(Kodelister::VARIANT_PRODUKSJON, Kodelister::VARIANTFORMATER);
        self::assertContains(Kodelister::VARIANT_ARKIV, Kodelister::VARIANTFORMATER);
        // Avskrivningsmåtene fra Noark 5 tjenestegrensesnitt. BU og SA kan ikke velges ved avskrivning uten svar.
        foreach (['BU', 'BE', 'TLF', 'TE', 'TO', 'BN', 'SA'] as $kode) {
            self::assertContains($kode, Kodelister::AVSKRIVNINGSMATER);
        }
        self::assertSame([], array_diff(Kodelister::AVSKRIVNING_UTEN_SVAR, Kodelister::AVSKRIVNINGSMATER));
        self::assertNotContains(Kodelister::AVSKR_BREV, Kodelister::AVSKRIVNING_UTEN_SVAR);
        self::assertNotContains(Kodelister::AVSKR_SAK_AVSLUTTET, Kodelister::AVSKRIVNING_UTEN_SVAR);
    }

    public function testDokumentfilenErIArkivformatNaarJournalpostenErJournalfortEkspedertEllerArkivert(): void
    {
        foreach (Kodelister::MED_ARKIVFORMAT as $status) {
            self::assertSame(Kodelister::VARIANT_ARKIV, Kodelister::variantformat($status));
        }
        foreach (array_diff(Kodelister::JOURNALSTATUSER, Kodelister::MED_ARKIVFORMAT) as $status) {
            self::assertSame(Kodelister::VARIANT_PRODUKSJON, Kodelister::variantformat($status), "Journalstatus $status: dokumentet er ikke gjort om til arkivformat ennå.");
        }
    }
}
