<?php

declare(strict_types=1);

/*
 * Kontrollerer Kjerne med de prefiksede avhengighetene (BIV-B-024), slik versjonspakken blir, uten utviklingsverktøy:
 *  - klassene i vendor/ har prefikset Bivrost\Vendor\ (unntatt polyfillene, psr/clock og Composers egne)
 *  - filene i «files» har egne nøkler (unntatt polyfillene), så appens kopi ikke stenger dem ute
 *  - Kjernens kode bruker bare de prefiksede navnene
 *  - alle klassene i src/ lastes med kjerne/autoload.php
 *
 * Bruk: php skript/scoper/sjekk-pakke.php <kjerne-mappe>   (vendor/ i mappen er den prefiksede)
 */

$k = rtrim($argv[1] ?? '', '/');
$feil = [];
$vendor = is_file("$k/vendor-prefikset/autoload.php") && !is_file("$k/versjon.json") && ($argv[2] ?? '') === '--utvikling' ? "$k/vendor-prefikset" : "$k/vendor";
// Unntakene: Composers ClassLoader (felles for alle vendor-mapper), psr/clock og alt i polyfillene (globale klasser som \\Normalizer).
$tillatt = '/^(Bivrost\\\\Vendor\\\\|Composer\\\\Autoload\\\\ClassLoader$|Psr\\\\Clock\\\\)/';

$klassekart = require "$vendor/composer/autoload_classmap.php";
foreach (is_array($klassekart) ? $klassekart : [] as $klasse => $fil) {
    if (preg_match($tillatt, (string) $klasse) !== 1 && !str_contains(str_replace('\\', '/', (string) $fil), '/symfony/polyfill-')) {
        $feil[] = "uprefikset klasse i vendor/: $klasse";
    }
}
$filer = is_file("$vendor/composer/autoload_files.php") ? require "$vendor/composer/autoload_files.php" : [];
$installert = json_decode((string) file_get_contents("$vendor/composer/installed.json"), true);
foreach (is_array($filer) ? $filer : [] as $nokkel => $sti) {
    foreach (is_array($installert) ? $installert['packages'] ?? [] : [] as $p) {
        $mappe = '/' . $p['name'] . '/';
        if (str_contains((string) $sti, $mappe)) {
            $original = md5($p['name'] . ':' . substr((string) $sti, strpos((string) $sti, $mappe) + strlen($mappe)));
            $polyfill = str_starts_with($p['name'], 'symfony/polyfill-');
            if ($polyfill !== ($nokkel === $original)) {
                $feil[] = ($polyfill ? 'polyfillen har fått ny nøkkel: ' : 'samme nøkkel som en uprefikset kopi: ') . $sti;
            }
        }
    }
}
// Fast navn på lasterklassen, ellers kan den hete det samme som appens (navnet lages av composer.lock).
if (!str_contains((string) file_get_contents("$vendor/autoload.php"), 'ComposerAutoloaderInitBivrostKjerneVendor')) {
    $feil[] = 'lasterklassen heter ikke ComposerAutoloaderInitBivrostKjerneVendor (autoloader-suffix i prefiks.sh).';
}
foreach (['InstalledVersions.php', 'installed.php'] as $f) {
    if (is_file("$vendor/composer/$f")) {
        $feil[] = "composer/$f skal ikke være med (appens InstalledVersions ville svart med Kjernens pakker).";
    }
}
// Ukvalifiserte kall til funksjoner som er flyttet inn i Bivrost\Vendor\ (patchen i scoper.inc.php).
$flyttede = [];
$alleFiler = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS)));
foreach ($alleFiler as $f) {
    if ($f instanceof SplFileInfo && $f->getExtension() === 'php') {
        $kode = (string) file_get_contents($f->getPathname());
        if (preg_match('/^namespace Bivrost\\\\Vendor;/m', $kode) === 1 && preg_match_all('/^\s*function\s+([A-Za-z_]\w*)\s*\(/m', $kode, $m) > 0) {
            array_push($flyttede, ...$m[1]);
        }
    }
}
if ($flyttede !== []) {
    $monster = '/(?<![\\\\\\w$>:])(?<!function )(' . implode('|', array_map('preg_quote', array_unique($flyttede))) . ')\s*\(/';
    foreach ($alleFiler as $f) {
        if ($f instanceof SplFileInfo && $f->getExtension() === 'php' && !str_contains($f->getPathname(), '/symfony/polyfill-')) {
            $kode = (string) file_get_contents($f->getPathname());
            if (preg_match('/^namespace Bivrost\\\\Vendor\\\\/m', $kode) === 1 && preg_match($monster, $kode, $m) === 1) {
                $feil[] = 'ukvalifisert kall til ' . $m[1] . ' i ' . substr($f->getPathname(), strlen($vendor) + 1);
            }
        }
    }
}
if (is_file("$vendor/scoper-autoload.php")) {
    $feil[] = 'scoper-autoload.php skal ikke være med (globale alias).';
}

$navn = 'Firebase|GuzzleHttp|League|OTPHP|ParagonIE|Composer\\\\Semver|Psr\\\\Http';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$k/src", FilesystemIterator::SKIP_DOTS));
$klasser = [];
foreach ($it as $f) {
    if (!$f instanceof SplFileInfo || $f->getExtension() !== 'php') {
        continue;
    }
    $kode = (string) file_get_contents($f->getPathname());
    if (preg_match("/(^use\\s+|[^\\\\A-Za-z0-9_]\\\\?)($navn)\\\\/m", $kode, $m) === 1) {
        $feil[] = 'uprefikset avhengighet i ' . substr($f->getPathname(), strlen($k) + 1) . ': ' . trim($m[0]);
    }
    $klasser[] = 'Bivrost\\Kjerne\\' . str_replace('/', '\\', substr($f->getPathname(), strlen("$k/src/"), -4));
}

require "$k/autoload.php";
foreach ($klasser as $klasse) {
    try {
        if (!class_exists($klasse) && !interface_exists($klasse) && !trait_exists($klasse) && !enum_exists($klasse)) {
            $feil[] = "lastes ikke: $klasse";
        }
    } catch (Throwable $e) {
        $feil[] = "lastes ikke: $klasse (" . $e->getMessage() . ')';
    }
}

// otphp uten klokke kaller trigger_deprecation: den skal finnes som Bivrost\Vendor\trigger_deprecation.
try {
    set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);
    new Bivrost\Vendor\OTPHP\TOTP('JBSWY3DPEHPK3PXP');
} catch (Throwable $e) {
    $feil[] = 'otphp uten klokke: ' . $e->getMessage();
} finally {
    restore_error_handler();
}

if ($feil !== []) {
    fwrite(STDERR, "Kjerne med prefiksede avhengigheter: " . count($feil) . " feil\n  " . implode("\n  ", $feil) . "\n");
    exit(1);
}
echo 'Kjerne med prefiksede avhengigheter: ' . count($klassekart) . ' klasser i vendor/, ' . count($klasser) . " klasser i src/ lastet, ingen uprefiksede navn.\n";
