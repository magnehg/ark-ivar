<?php

/**
 * Oversettelser til og fra XLIFF 2.0 (BIV-SPR-06):
 *   php bin/xliff.php eksporter <målspråk> [<kildespråk>=nb] > bivrost-kjerne.<målspråk>.xlf
 *   php bin/xliff.php importer <fil.xlf>          (skriver i18n/<målspråk>/<modul>.json)
 * Kan også brukes på appens språkfiler: --i18n=<mappe>.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Bivrost\Kjerne\Sprak\Xliff;

$args = array_slice($argv, 1);
$i18n = dirname(__DIR__) . '/i18n';
foreach ($args as $n => $a) {
    if (str_starts_with($a, '--i18n=')) {
        $i18n = substr($a, 7);
        unset($args[$n]);
    }
}
$args = array_values($args);
try {
    if (($args[0] ?? '') === 'eksporter' && isset($args[1])) {
        echo Xliff::eksporter($i18n, $args[2] ?? 'nb', $args[1]);
        exit(0);
    }
    if (($args[0] ?? '') === 'importer' && isset($args[1]) && is_file($args[1])) {
        $n = Xliff::skriv($i18n, (string) file_get_contents($args[1]));
        fwrite(STDOUT, "Skrev $n tekster.\n");
        exit(0);
    }
} catch (Bivrost\Kjerne\Feil\BivrostFeil $e) {
    fwrite(STDERR, $e->tekstnokkel . "\n");
    exit(1);
}
fwrite(STDERR, "Bruk: php bin/xliff.php eksporter <målspråk> [<kildespråk>] | importer <fil.xlf> [--i18n=<mappe>]\n");
exit(2);
