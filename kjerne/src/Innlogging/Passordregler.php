<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Innlogging;

use Bivrost\Kjerne\Database\Felt;

/**
 * Passordregler etter NIST SP 800-63B (rev. 4) for den lokale nødinnloggingen (BIV-AUT-04): lengde fremfor
 * komposisjonsregler, ingen tvungen bytting, alle Unicode-tegn og mellomrom er lov, og passordet sjekkes mot en liste
 * over vanlige og lekkede passord og mot ord fra sammenhengen (brukernavn, appnavn). Passordet normaliseres (NFC)
 * før kontrollen og hashingen. Lokal innlogging krever alltid tofaktor (TOTP), så minstelengden 12 ligger over
 * NISTs krav for passord sammen med en annen faktor (8), og høyst 64 tegn er lov.
 */
final class Passordregler
{
    public const MIN = 12;
    public const MAKS = 64;

    /** @var array<string, true>|null */
    private static ?array $vanlige = null;

    /**
     * Tekstnøkkelen for den første regelen passordet bryter, eller null når det er godt nok.
     *
     * @param list<string> $sammenheng Ord som ikke skal være passordet eller hoveddelen av det.
     */
    public static function feil(string $passord, array $sammenheng = []): ?string
    {
        if (!mb_check_encoding($passord, 'UTF-8')) {
            return 'bivrost.feil.passord.tegn';
        }
        $p = self::normaliser($passord);
        $lengde = mb_strlen($p, 'UTF-8');
        if ($lengde < self::MIN) {
            return 'bivrost.feil.passord.kort';
        }
        if ($lengde > self::MAKS) {
            return 'bivrost.feil.passord.langt';
        }
        $smaa = mb_strtolower($p, 'UTF-8');
        $uten = (string) preg_replace('/[\s\d\W_]+/u', '', $smaa);
        if (isset(self::vanlige()[$smaa]) || ($uten !== '' && isset(self::vanlige()[$uten]))) {
            return 'bivrost.feil.passord.vanlig';
        }
        if (count(array_unique(mb_str_split($smaa, 1, 'UTF-8'))) <= 3) {
            return 'bivrost.feil.passord.gjentatt';
        }
        if (self::rekke($smaa)) {
            return 'bivrost.feil.passord.rekke';
        }
        foreach ($sammenheng as $ord) {
            $o = mb_strtolower(self::normaliser($ord), 'UTF-8');
            if (mb_strlen($o, 'UTF-8') >= 4 && ($smaa === $o || str_contains($smaa, $o) && mb_strlen($smaa, 'UTF-8') - mb_strlen($o, 'UTF-8') < 6)) {
                return 'bivrost.feil.passord.sammenheng';
            }
        }

        return null;
    }

    public static function normaliser(string $passord): string
    {
        // Normalizer kommer fra intl eller fra symfony/polyfill-intl-normalizer, så samme passord gir samme hash overalt.
        $n = \Normalizer::normalize($passord, \Normalizer::FORM_C);

        return is_string($n) ? $n : $passord;
    }

    /** Om passordet bare er en stigende eller synkende rekke (abcdef…, 123456…, qwerty…). */
    private static function rekke(string $p): bool
    {
        foreach (['abcdefghijklmnopqrstuvwxyzæøå', '01234567890', 'qwertyuiopåasdfghjkløæzxcvbnm', '1qaz2wsx3edc4rfv5tgb6yhn'] as $rekke) {
            if (str_contains($rekke, $p) || str_contains(strrev($rekke), $p)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private static function vanlige(): array
    {
        if (self::$vanlige === null) {
            $liste = Felt::ellers(@file(__DIR__ . '/../../ressurser/vanlige-passord.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), []);
            self::$vanlige = [];
            foreach ($liste as $l) {
                $l = trim($l);
                if ($l !== '' && !str_starts_with($l, '#')) {
                    self::$vanlige[mb_strtolower($l, 'UTF-8')] = true;
                }
            }
        }

        return self::$vanlige;
    }
}
