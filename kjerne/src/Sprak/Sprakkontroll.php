<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sprak;

use Bivrost\Kjerne\Database\Felt;

/**
 * Kontrollen av språkfilene (BIV-SPR-08), kjørt av bin/sjekk-sprak.php i CI og lokalt:
 *  - de samme filene og nøklene på alle språkene (manglende nøkler), og nøkkelprefikset til eieren
 *  - gyldig ICU MessageFormat (MessageFormatter når intl finnes, ellers IcuFormatter)
 *  - de samme plassholderne på alle språkene
 *  - nøkler i koden som ikke finnes, og nøkler i filene som ikke brukes (dynamiske prefikser står i
 *    i18n/dynamiske-nokler.txt)
 *  - ingen bokstavelig brukertekst i PHP-koden (setninger i strenger utenfor kommentarer og utviklerfeil) eller i
 *    tekstnoder i TSX
 * Gir en liste med funn; tom liste betyr at alt er i orden.
 */
final class Sprakkontroll
{
    /**
     * @param list<string> $kode Mapper med kode som skal sjekkes for nøkler og bokstavelig tekst.
     * @return list<string>
     */
    public static function kjor(string $i18n, string $prefiks, array $kode, string $hovedsprak = 'nb'): array
    {
        $funn = [];
        $filer = [];
        foreach (Felt::ellers(glob("$i18n/*", GLOB_ONLYDIR), []) as $mappe) {
            $sprak = basename($mappe);
            foreach (Felt::ellers(glob("$mappe/*.json"), []) as $fil) {
                if (basename($fil) === 'sprakpakke.json') {
                    if (Sprakpakke::les($fil) === null) {
                        $funn[] = "$sprak/sprakpakke.json: ugyldig manifest";
                    }
                    continue;
                }
                $j = json_decode((string) file_get_contents($fil), true);
                if (!is_array($j)) {
                    $funn[] = "$sprak/" . basename($fil) . ': ikke gyldig JSON';
                    continue;
                }
                foreach ($j as $k => $v) {
                    if (!is_string($v)) {
                        $funn[] = "$sprak/" . basename($fil) . ": $k er ikke en tekst";
                        continue;
                    }
                    $filer[basename($fil)][$sprak][(string) $k] = $v;
                }
            }
        }
        $alleNokler = [];
        foreach ($filer as $fil => $perSprak) {
            $hoved = $perSprak[$hovedsprak] ?? [];
            foreach (array_keys($hoved) as $k) {
                $alleNokler[$k] = true;
                if (!str_starts_with($k, $prefiks)) {
                    $funn[] = "$hovedsprak/$fil: $k mangler prefikset $prefiks";
                }
            }
            foreach ($perSprak as $sprak => $tekster) {
                foreach (array_diff_key($hoved, $tekster) as $k => $_) {
                    if (in_array($sprak, Tekster::INNEBYGDE, true)) {
                        $funn[] = "$sprak/$fil: mangler $k";
                    }
                }
                foreach (array_diff_key($tekster, $hoved) as $k => $_) {
                    $funn[] = "$sprak/$fil: $k finnes ikke på $hovedsprak";
                }
                foreach ($tekster as $k => $v) {
                    if (!self::gyldigIcu($v)) {
                        $funn[] = "$sprak/$fil: $k er ikke gyldig ICU MessageFormat";
                    }
                    if (isset($hoved[$k]) && self::plassholdere($v) !== self::plassholdere($hoved[$k])) {
                        $funn[] = "$sprak/$fil: $k har andre plassholdere enn $hovedsprak";
                    }
                }
            }
            foreach (Tekster::INNEBYGDE as $s) {
                if (!isset($perSprak[$s])) {
                    $funn[] = "$s/$fil: filen mangler";
                }
            }
        }
        $dynamiske = [];
        foreach (Felt::ellers(@file("$i18n/dynamiske-nokler.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), []) as $l) {
            $l = trim($l);
            if ($l !== '' && !str_starts_with($l, '#')) {
                $dynamiske[] = $l;
            }
        }
        $brukt = [];
        foreach (self::filer($kode, ['php', 'ts', 'tsx']) as $fil) {
            $tekst = (string) file_get_contents($fil);
            preg_match_all('/[\'"`](' . preg_quote($prefiks, '/') . '[a-z0-9_.-]+[a-z0-9_])[\'"`]/', $tekst, $m);
            foreach ($m[1] as $k) {
                $brukt[$k] = true;
                if (!isset($alleNokler[$k])) {
                    $funn[] = self::rel($fil) . ": nøkkelen $k finnes ikke i språkfilene";
                }
            }
            $funn = array_merge($funn, str_ends_with($fil, '.php') ? self::bokstaveligPhp($fil, $tekst) : self::bokstaveligTsx($fil, $tekst));
        }
        foreach (array_keys($alleNokler) as $k) {
            $dyn = false;
            foreach ($dynamiske as $d) {
                $dyn = $dyn || str_starts_with($k, $d);
            }
            if (!isset($brukt[$k]) && !$dyn) {
                $funn[] = "$hovedsprak: $k brukes ikke i koden";
            }
        }
        sort($funn);

        return array_values(array_unique($funn));
    }

    public static function gyldigIcu(string $m): bool
    {
        if (class_exists(\MessageFormatter::class)) {
            try {
                new \MessageFormatter('nb_NO', $m);

                return true;
            } catch (\IntlException) {
                return false;
            }
        }

        return IcuFormatter::gyldig($m);
    }

    /** @return list<string> */
    public static function plassholdere(string $m): array
    {
        preg_match_all('/\{\s*([A-Za-z0-9_]+)\s*[,}]/', $m, $x);
        $p = array_values(array_unique($x[1]));
        sort($p);

        return $p;
    }

    /**
     * @param list<string> $mapper
     * @param list<string> $endinger
     * @return list<string>
     */
    private static function filer(array $mapper, array $endinger): array
    {
        $ut = [];
        foreach ($mapper as $m) {
            if (!is_dir($m)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($m, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && in_array($f->getExtension(), $endinger, true) && !str_contains($f->getPathname(), '/vendor/') && !str_contains($f->getPathname(), '/node_modules/')) {
                    $ut[] = $f->getPathname();
                }
            }
        }
        sort($ut);

        return $ut;
    }

    private static function rel(string $fil): string
    {
        $p = strpos($fil, '/bivrost/');

        return $p === false ? $fil : substr($fil, $p + 9);
    }

    /**
     * Setninger i strenger som ikke er utviklerfeil (unntak med teknisk melding), logg til serveren eller tekniske
     * verdier: begynner med stor bokstav, har mellomrom og slutter med punktum, utropstegn eller spørsmålstegn.
     *
     * @return list<string>
     */
    private static function bokstaveligPhp(string $fil, string $kode): array
    {
        $funn = [];
        $tok = token_get_all($kode);
        $foran = [];
        foreach ($tok as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $s = substr($t[1], 1, -1);
                $kontekst = implode(' ', array_slice($foran, -4));
                $utvikler = preg_match('/(Exception|Error|error_log|sprintf)\b/', $kontekst) === 1;
                $sql = preg_match('/^(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|SHOW|SET|START|ALTER|COMMIT)\b/', $s) === 1;
                if (!$utvikler && !$sql && preg_match('/^[A-ZÆØÅ][^\s]*\s.+[.!?]$/u', $s) === 1) {
                    $funn[] = self::rel($fil) . ":{$t[2]}: bokstavelig tekst «" . mb_substr($s, 0, 60) . '» – bruk en nøkkel i språkfilene';
                }
            }
            $foran[] = is_array($t) ? $t[1] : $t;
        }

        return $funn;
    }

    /** @return list<string> */
    private static function bokstaveligTsx(string $fil, string $kode): array
    {
        if (!str_ends_with($fil, '.tsx')) {
            return [];
        }
        $funn = [];
        foreach (explode("\n", $kode) as $nr => $linje) {
            if (preg_match('/>\s*([A-Za-zÆØÅæøå][^<>{}]*[A-Za-zæøå.!?])\s*</u', $linje, $m) === 1) {
                $funn[] = self::rel($fil) . ':' . ($nr + 1) . ': bokstavelig tekst «' . $m[1] . '» i TSX – bruk t()';
            }
        }

        return $funn;
    }
}
