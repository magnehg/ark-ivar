<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

/**
 * Dokumentfilene til en journalpost, slik de ligger i et sak- og arkivsystem: filen i produksjonsformat (slik den
 * ble laget eller mottatt), og i tillegg filen i arkivformat når journalposten er journalført, ekspedert eller
 * arkivert. Filene hører til en versjon av dokumentet; øvingsarkivet har bare én versjon av hvert dokument. Øvingsarkivet har ikke
 * virkelige filer: innholdet er dokumentet i grunndataene, og listen her sier hvilke filer det ville ligget som.
 *
 * Filformatet følger av hvordan dokumentet ble til: e-post er lagret som e-postfil, brev og skjemaer som er kommet
 * på papir, er skannet, andre dokumenter utenfra er mottatt som PDF, og kommunens egne er skrevet i tekstbehandler.
 * Arkivformatet er ren tekst for e-post og PDF/A for alt annet.
 *
 * @phpstan-type Dokumentfil array{versjon: int, variantformat: string, format: string, filnavn: string}
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
    /** Versjonsnummeret: dokumentene i øvingsarkivet har én versjon. */
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
     * Filene til en journalpost med denne statusen. Produksjonsformatet finnes alltid; arkivformatet kommer i
     * tillegg når journalposten er journalført, ekspedert eller arkivert (Kodelister::variantformat()).
     *
     * @return list<Dokumentfil>
     */
    public static function filer(string $kanal, ?string $mottatt, string $journalstatus, string $tittel): array
    {
        $navn = self::filnavn($tittel);
        $produksjon = self::produksjonsformat($kanal, $mottatt);
        $filer = [['versjon' => self::VERSJON, 'variantformat' => Kodelister::VARIANT_PRODUKSJON, 'format' => $produksjon, 'filnavn' => $navn . '.' . $produksjon]];
        if (Kodelister::variantformat($journalstatus) === Kodelister::VARIANT_ARKIV) {
            $arkiv = self::arkivformat($kanal);
            $filer[] = ['versjon' => self::VERSJON, 'variantformat' => Kodelister::VARIANT_ARKIV, 'format' => $arkiv, 'filnavn' => $navn . '.' . $arkiv];
        }

        return $filer;
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
