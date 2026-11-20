<?php

declare(strict_types=1);

/*
 * Etterbehandling av den prefiksede vendor/ (BIV-B-024), etter composer dump-autoload:
 *
 * 1. Filene Composer laster med «files» (f.eks. symfony/deprecation-contracts) får nye nøkler. Composer laster en slik
 *    fil bare én gang per nøkkel, og nøkkelen er md5 av pakkenavnet og stien. Uten nye nøkler ville appens uprefiksede
 *    kopi av samme pakke gjort at Kjernens prefiksede aldri ble lastet. Polyfillene beholder nøkkelen, så de lastes
 *    bare én gang til sammen.
 * 2. Composer\InstalledVersions og installed.php fjernes. Kjerne bruker dem ikke, og Composers felles ClassLoader ville
 *    ellers latt appens InstalledVersions svare med Kjernens pakker (guzzlehttp/guzzle 8, rotpakken bivrost/kjerne).
 *    installed.json blir liggende: publiseringsjobben sammenligner den med composer.lock.
 *
 * Bruk: php skript/scoper/etterbehandling.php <vendor-mappe>
 */

$vendor = rtrim($argv[1] ?? '', '/');
if (!is_file("$vendor/composer/autoload_static.php")) {
    fwrite(STDERR, "Fant ikke $vendor/composer/autoload_static.php\n");
    exit(1);
}

$skriv = static function (string $fil, string $innhold): void {
    if (file_put_contents($fil, $innhold) === false) {
        fwrite(STDERR, "Kunne ikke skrive $fil\n");
        exit(1);
    }
};

// 1. Nye nøkler for «files».
$byttet = 0;
foreach (["$vendor/composer/autoload_files.php", "$vendor/composer/autoload_static.php"] as $fil) {
    if (!is_file($fil)) {
        continue; // Ingen «files» i det hele tatt.
    }
    $ny = preg_replace_callback(
        "/^(\\s*)'([0-9a-f]{32})' => (.+)$/m",
        static function (array $m) use (&$byttet): string {
            if (str_contains($m[3], '/symfony/polyfill-')) {
                return $m[0];
            }
            $byttet++;

            return $m[1] . "'" . md5('bivrost-vendor:' . $m[2]) . "' => " . $m[3];
        },
        (string) file_get_contents($fil),
    );
    $skriv($fil, (string) $ny);
}

// 2. InstalledVersions ut av klassekartet, og filene bort.
$fjernet = 0;
foreach (["$vendor/composer/autoload_classmap.php", "$vendor/composer/autoload_static.php"] as $fil) {
    $ny = preg_replace("/^\\s*'Composer\\\\\\\\InstalledVersions' => .*\\n/m", '', (string) file_get_contents($fil), -1, $antall);
    $fjernet += $antall;
    $skriv($fil, (string) $ny);
}
foreach (["$vendor/composer/InstalledVersions.php", "$vendor/composer/installed.php"] as $fil) {
    if (is_file($fil) && !unlink($fil)) {
        fwrite(STDERR, "Kunne ikke slette $fil\n");
        exit(1);
    }
}
if ($fjernet !== 2) {
    fwrite(STDERR, "Fant ikke Composer\\InstalledVersions i begge klassekartene ($fjernet).\n");
    exit(1);
}
echo "Nye nøkler for $byttet oppføringer i «files»; InstalledVersions og installed.php fjernet.\n";
