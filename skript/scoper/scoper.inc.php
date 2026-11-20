<?php

declare(strict_types=1);

/*
 * Oppsettet for php-scoper (BIV-B-024): Kjernens avhengigheter får prefikset Bivrost\Vendor\, så Kjerne og appen kan
 * bruke samme bibliotek i ulike versjoner. Kjøres av skript/prefiks.sh i en mappe med bare composer.json, composer.lock
 * og vendor/ (uten utviklingsavhengigheter). Kjernens egen kode (kjerne/src) prefikses ikke: den bruker de prefiksede
 * navnene direkte, så filene i versjonspakken er like filene i commiten.
 *
 * Unntak, som deles med appen og derfor beholder navnet:
 *  - Polyfillene (symfony/polyfill-*): de definerer globale klasser som \Normalizer og er like i alle versjoner.
 *  - psr/clock (Psr\Clock\ClockInterface): en del av Kjernens grensesnitt mot appene (PSR-20, bare versjon 1.0.0).
 */

$rot = getcwd();
if ($rot === false) {
    throw new RuntimeException('Fant ikke arbeidsmappen.');
}
$uendret = [];
foreach (array_merge(glob($rot . '/vendor/symfony/polyfill-*', GLOB_ONLYDIR) ?: [], glob($rot . '/vendor/psr/clock', GLOB_ONLYDIR) ?: []) as $mappe) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mappe, FilesystemIterator::SKIP_DOTS)) as $fil) {
        if ($fil instanceof SplFileInfo && $fil->isFile()) {
            $uendret[] = $fil->getPathname();
        }
    }
}

// Globale funksjoner som php-scoper flytter inn i Bivrost\Vendor\ (f.eks. trigger_deprecation fra
// symfony/deprecation-contracts): filer uten navnerom, som ikke er unntatt.
$flyttedeFunksjoner = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rot . '/vendor', FilesystemIterator::SKIP_DOTS)) as $fil) {
    if (!$fil instanceof SplFileInfo || $fil->getExtension() !== 'php' || in_array($fil->getPathname(), $uendret, true)) {
        continue;
    }
    $kode = (string) file_get_contents($fil->getPathname());
    if (preg_match('/^\s*namespace\s/m', $kode) === 1) {
        continue;
    }
    preg_match_all('/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $kode, $m);
    foreach ($m[1] as $navn) {
        $flyttedeFunksjoner[$navn] = true;
    }
}
$flyttedeFunksjoner = array_keys($flyttedeFunksjoner);

return [
    'prefix' => 'Bivrost\\Vendor',
    'exclude-namespaces' => ['Symfony\\Polyfill', 'Psr\\Clock'],
    'exclude-classes' => ['Normalizer', 'Stringable', 'ValueError', 'UnhandledMatchError', 'Attribute', 'PhpToken', 'SensitiveParameter'],
    'exclude-files' => $uendret,
    'patchers' => [
        // php-scoper kvalifiserer ikke ukvalifiserte kall til funksjoner i kode med navnerom (den venter at
        // scoper-autoload.php lager globale alias, som Kjerne ikke vil ha). Uten dette ville f.eks. otphp kalt den
        // globale trigger_deprecation (appens, eller ingen) i stedet for Bivrost\Vendor\trigger_deprecation.
        static function (string $fil, string $prefiks, string $innhold) use ($flyttedeFunksjoner): string {
            if ($flyttedeFunksjoner === [] || preg_match('/^\s*namespace\s/m', $innhold) !== 1) {
                return $innhold;
            }
            $monster = '/(?<![\\\\\\w$>:])(?<!function )(' . implode('|', array_map('preg_quote', $flyttedeFunksjoner)) . ')\s*\(/';

            return (string) preg_replace($monster, '\\\\' . $prefiks . '\\\\$1(', $innhold);
        },
        // league/oauth2-client lager klassenavnet for grant-typen av tekst, som php-scoper ikke ser.
        static function (string $fil, string $prefiks, string $innhold): string {
            if (!str_ends_with(str_replace('\\', '/', $fil), 'league/oauth2-client/src/Grant/GrantFactory.php')) {
                return $innhold;
            }
            $fra = "'League\\OAuth2\\Client\\Grant\\\\'";
            $ny = str_replace($fra, "'" . $prefiks . "\\League\\OAuth2\\Client\\Grant\\\\'", $innhold, $antall);
            if ($antall !== 1) {
                throw new RuntimeException('GrantFactory i league/oauth2-client er endret: rett patchen i skript/scoper/scoper.inc.php.');
            }

            return $ny;
        },
    ],
];
