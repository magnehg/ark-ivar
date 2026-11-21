<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

/**
 * Dokumentfilene til en journalpost, slik de ligger i et sak- og arkivsystem: filen i produksjonsformat (slik den
 * ble laget eller mottatt), og i tillegg filen i arkivformat når journalposten er journalført, ekspedert eller
 * arkivert. Filene hører til en versjon av dokumentet. De fleste dokumentene har én versjon; noen utgående har
 * også saksbehandlerens første utkast som versjon 1. Øvingsarkivet har ikke
 * virkelige filer: innholdet er dokumentet i grunndataene, og listen her sier hvilke filer det ville ligget som.
 *
 * Filformatet følger av hvordan dokumentet ble til: e-post er lagret som e-postfil, brev og skjemaer som er kommet
 * på papir, er skannet, andre dokumenter utenfra er mottatt som PDF, og kommunens egne er skrevet i tekstbehandler.
 * Arkivformatet er ren tekst for e-post og PDF/A for alt annet.
 *
 * En journalpost har ett hoveddokument og kan ha vedlegg (Noark: tilknyttet registrering som H og V). Vedleggene i
 * øvingsarkivet har bare tittel, ikke innhold.
 *
 * @phpstan-type Dokumentfil array{versjon: int, variantformat: string, format: string, filnavn: string}
 * @phpstan-type Dokumentbeskrivelse array{nr: int, rolle: string, tittel: string, filer: list<Dokumentfil>}
 */
final class Dokumentfiler
{
    public const TEKSTBEHANDLER = 'docx';
    public const EPOST = 'eml';
    public const SKANNET = 'tif';
    public const PDF = 'pdf';
    public const TEKST = 'txt';
    public const PRODUKSJONSFORMATER = [self::TEKSTBEHANDLER, self::EPOST, self::SKANNET, self::PDF];
    public const ARKIVFORMATER = [self::PDF, self::TEKST];

    private const KANAL_EPOST = 'epost';
    /** Dokumenter som kommer på papir og skannes av arkivtjenesten. */
    private const PAPIR = ['brev', 'skjema'];
    private const NAVNELENGDE = 60;
    /** Det første versjonsnummeret. */
    private const VERSJON = 1;

    /** Formatet filen ble laget eller mottatt i. $mottatt er datoen et dokument utenfra kom inn (null for kommunens egne). */
    public static function produksjonsformat(string $kanal, ?string $mottatt): string
    {
        if ($kanal === self::KANAL_EPOST) {
            return self::EPOST;
        }
        if ($mottatt === null) {
            return self::TEKSTBEHANDLER;
        }

        return in_array($kanal, self::PAPIR, true) ? self::SKANNET : self::PDF;
    }

    /** Formatet dokumentet arkiveres i: e-post som ren tekst, alt annet som PDF. */
    public static function arkivformat(string $kanal): string
    {
        return $kanal === self::KANAL_EPOST ? self::TEKST : self::PDF;
    }

    /**
     * Filene til hoveddokumentet i en journalpost med denne statusen. Produksjonsformatet finnes alltid;
     * arkivformatet kommer i tillegg når journalposten er journalført, ekspedert eller arkivert
     * (Kodelister::variantformat()).
     *
     * @return list<Dokumentfil>
     */
    public static function filer(string $kanal, ?string $mottatt, string $journalstatus, string $tittel): array
    {
        return self::varianter(self::produksjonsformat($kanal, $mottatt), self::arkivformat($kanal), $journalstatus, $tittel);
    }

    /**
     * Formatet et vedlegg ble laget eller mottatt i: som hoveddokumentet, bortsett fra at et vedlegg til en e-post er
     * en PDF og ikke en e-postfil.
     */
    public static function vedleggsformat(string $kanal, ?string $mottatt): string
    {
        return $kanal === self::KANAL_EPOST ? self::PDF : self::produksjonsformat($kanal, $mottatt);
    }

    /**
     * Variantene av én versjon, arkivformatet først. $ferdig sier om versjonen er den gjeldende: en tidligere
     * versjon finnes bare i produksjonsformat, og filnavnet har versjonsnummeret.
     *
     * @return list<Dokumentfil>
     */
    private static function varianter(string $produksjon, string $arkiv, string $journalstatus, string $tittel, int $versjon = self::VERSJON, bool $ferdig = true): array
    {
        $navn = self::filnavn($tittel) . ($ferdig ? '' : '-v' . $versjon);
        $filer = [];
        if ($ferdig && Kodelister::variantformat($journalstatus) === Kodelister::VARIANT_ARKIV) {
            $filer[] = ['versjon' => $versjon, 'variantformat' => Kodelister::VARIANT_ARKIV, 'format' => $arkiv, 'filnavn' => $navn . '.' . $arkiv];
        }
        $filer[] = ['versjon' => $versjon, 'variantformat' => Kodelister::VARIANT_PRODUKSJON, 'format' => $produksjon, 'filnavn' => $navn . '.' . $produksjon];

        return $filer;
    }

    public const HOVEDDOKUMENT = 'H';
    public const VEDLEGG = 'V';

    /**
     * Dokumentene i en journalpost: hoveddokumentet først (nr. 1) og så vedleggene, hvert med filene sine. Et vedlegg
     * til en e-post er mottatt som PDF; ellers har vedleggene samme produksjonsformat som hoveddokumentet. Vedlegg
     * arkiveres alltid som PDF.
     *
     * @param list<string> $vedlegg titlene på vedleggene
     * @param int $versjoner antall versjoner av hoveddokumentet (utkast og tidligere versjoner kommer i tillegg til den gjeldende)
     * @return list<Dokumentbeskrivelse>
     */
    public static function dokumenter(string $kanal, ?string $mottatt, string $journalstatus, string $tittel, array $vedlegg, int $versjoner = 1): array
    {
        // Den nyeste versjonen står først og er den som kan ha arkivformat. De eldre finnes bare i produksjonsformat.
        $produksjon = self::produksjonsformat($kanal, $mottatt);
        $hoved = self::varianter($produksjon, self::arkivformat($kanal), $journalstatus, $tittel, max(1, $versjoner));
        for ($versjon = $versjoner - 1; $versjon >= 1; $versjon--) {
            $hoved = [...$hoved, ...self::varianter($produksjon, self::arkivformat($kanal), $journalstatus, $tittel, $versjon, false)];
        }
        $ut = [['nr' => 1, 'rolle' => self::HOVEDDOKUMENT, 'tittel' => $tittel, 'filer' => $hoved]];
        foreach ($vedlegg as $indeks => $navn) {
            $ut[] = ['nr' => $indeks + 2, 'rolle' => self::VEDLEGG, 'tittel' => $navn, 'filer' => self::varianter(self::vedleggsformat($kanal, $mottatt), self::PDF, $journalstatus, $navn)];
        }

        return $ut;
    }

    /** Et filnavn av tittelen: små bokstaver uten æ, ø og å, med bindestrek mellom ordene. */
    public static function filnavn(string $tittel): string
    {
        $tekst = strtr(mb_strtolower($tittel, 'UTF-8'), ['æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ö' => 'o', 'ä' => 'a', 'ü' => 'u']);
        $navn = trim((string) preg_replace('/[^a-z0-9]+/', '-', $tekst), '-');
        $navn = rtrim(substr($navn, 0, self::NAVNELENGDE), '-');

        return $navn !== '' ? $navn : 'dokument';
    }
}
