<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sprak;

/**
 * En liten ICU MessageFormat-formatter for servere uten intl (BIV-SPR-03). Dekker det Bivrosts tekster bruker:
 * {navn}, {n, number}, {n, plural, =0 {…} one {…} other {…}} med #, {x, select, a {…} other {…}} og '' for apostrof.
 * Flertall følger reglene for nb, nn og en (one = 1, ellers other); andre språk bruker other. intl (MessageFormatter)
 * er anbefalt og brukes når den finnes; denne testes mot den.
 */
final class IcuFormatter
{
    /** @param array<string, scalar|null> $v */
    public static function formater(string $monster, array $v, string $sprak = 'nb'): string
    {
        $i = 0;

        return self::tekst($monster, $i, $v, $sprak, null, false);
    }

    /** Om mønsteret er gyldig (balanserte klammer og kjente typer). */
    public static function gyldig(string $monster): bool
    {
        try {
            $i = 0;
            self::tekst($monster, $i, [], 'nb', null, false);

            return $i >= strlen($monster);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param array<string, scalar|null> $v
     */
    private static function tekst(string $m, int &$i, array $v, string $sprak, ?float $tall, bool $innenfor): string
    {
        $ut = '';
        $n = strlen($m);
        while ($i < $n) {
            $c = $m[$i];
            if ($c === "'") {
                if (($m[$i + 1] ?? '') === "'") {
                    $ut .= "'";
                    $i += 2;
                    continue;
                }
                $slutt = strpos($m, "'", $i + 1);
                if ($slutt !== false && in_array($m[$i + 1] ?? '', ['{', '}', '#'], true)) {
                    $ut .= substr($m, $i + 1, $slutt - $i - 1);
                    $i = $slutt + 1;
                    continue;
                }
                $ut .= "'";
                $i++;
                continue;
            }
            if ($c === '}') {
                if (!$innenfor) {
                    throw new \InvalidArgumentException('Ubalansert }');
                }

                return $ut;
            }
            if ($c === '#' && $tall !== null) {
                $ut .= self::tall($tall, $sprak);
                $i++;
                continue;
            }
            if ($c === '{') {
                $i++;
                $ut .= self::argument($m, $i, $v, $sprak);
                continue;
            }
            $ut .= $c;
            $i++;
        }
        if ($innenfor) {
            throw new \InvalidArgumentException('Mangler }');
        }

        return $ut;
    }

    /** @param array<string, scalar|null> $v */
    private static function argument(string $m, int &$i, array $v, string $sprak): string
    {
        $navn = self::ord($m, $i);
        if ($navn === '') {
            throw new \InvalidArgumentException('Tomt argument');
        }
        $verdi = $v[$navn] ?? null;
        self::hopp($m, $i);
        if (($m[$i] ?? '') === '}') {
            $i++;

            return $verdi === null ? '{' . $navn . '}' : (is_bool($verdi) ? ($verdi ? '1' : '0') : (string) $verdi);
        }
        if (($m[$i] ?? '') !== ',') {
            throw new \InvalidArgumentException('Ventet , eller }');
        }
        $i++;
        $type = self::ord($m, $i);
        self::hopp($m, $i);
        if ($type === 'number' || $type === 'date' || $type === 'time') {
            while ($i < strlen($m) && $m[$i] !== '}') {
                $i++;
            }
            $i++;

            return $type === 'number' && is_numeric($verdi) ? self::tall((float) $verdi, $sprak) : (string) ($verdi ?? '');
        }
        if ($type !== 'plural' && $type !== 'select' && $type !== 'selectordinal') {
            throw new \InvalidArgumentException('Ukjent type ' . $type);
        }
        if (($m[$i] ?? '') !== ',') {
            throw new \InvalidArgumentException('Ventet ,');
        }
        $i++;
        $valg = [];
        $forskyvning = 0.0;
        while (true) {
            self::hopp($m, $i);
            if (($m[$i] ?? '') === '}') {
                $i++;
                break;
            }
            $nokkel = self::ord($m, $i, true);
            if (str_starts_with($nokkel, 'offset:')) {
                $forskyvning = (float) substr($nokkel, 7);
                continue;
            }
            self::hopp($m, $i);
            if ($nokkel === '' || ($m[$i] ?? '') !== '{') {
                throw new \InvalidArgumentException('Ventet {');
            }
            $i++;
            $start = $i;
            // Les grenen uformatert først (for å finne slutten), formater den valgte etterpå.
            self::tekst($m, $i, [], $sprak, 0.0, true);
            $valg[$nokkel] = substr($m, $start, $i - $start);
            $i++;
        }
        if (!isset($valg['other'])) {
            throw new \InvalidArgumentException('Mangler other');
        }
        if ($type === 'select') {
            $gren = $valg[(string) ($verdi ?? 'other')] ?? $valg['other'];
            $j = 0;

            return self::tekst($gren . '}', $j, $v, $sprak, null, true);
        }
        $x = is_numeric($verdi) ? (float) $verdi : 0.0;
        $gren = $valg['=' . self::tall($x, 'en')] ?? null;
        if ($gren === null) {
            $kategori = ($type === 'plural' && in_array($sprak, ['nb', 'nn', 'en', 'no'], true) && $x - $forskyvning === 1.0) ? 'one' : 'other';
            $gren = $valg[$kategori] ?? $valg['other'];
        }
        $j = 0;

        return self::tekst($gren . '}', $j, $v, $sprak, $x - $forskyvning, true);
    }

    private static function ord(string $m, int &$i, bool $tillatTegn = false): string
    {
        self::hopp($m, $i);
        $start = $i;
        $monster = $tillatTegn ? '/[A-Za-z0-9_=:.\-]/' : '/[A-Za-z0-9_]/';
        while ($i < strlen($m) && preg_match($monster, $m[$i]) === 1) {
            $i++;
        }

        return substr($m, $start, $i - $start);
    }

    private static function hopp(string $m, int &$i): void
    {
        while ($i < strlen($m) && ctype_space($m[$i])) {
            $i++;
        }
    }

    private static function tall(float $x, string $sprak): string
    {
        $heltall = floor($x) === $x;
        $s = $heltall ? number_format($x, 0, ',', ' ') : rtrim(rtrim(number_format($x, 3, ',', ' '), '0'), ',');
        if ($sprak === 'en') {
            $s = $heltall ? number_format($x, 0, '.', ',') : rtrim(rtrim(number_format($x, 3, '.', ','), '0'), '.');
        }

        return $s;
    }
}
