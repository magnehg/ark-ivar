<?php

/**
 * Kontrollerer språkfilene i Ark-Ivar med Kjernens språkkontroll: php arkivar/bin/sjekk-sprak.php
 * Avslutter med feilkode 1 når noe ikke er i orden.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$rot = dirname(__DIR__);
$kode = array_values(array_filter([$rot . '/src', dirname($rot) . '/web/src'], is_dir(...)));
$funn = Bivrost\Kjerne\Sprak\Sprakkontroll::kjor($rot . '/i18n', 'arkivar.', $kode);
foreach ($funn as $f) {
    fwrite(STDOUT, $f . "\n");
}
fwrite(STDOUT, $funn === [] ? "Språkfilene er i orden.\n" : "\n" . count($funn) . " funn.\n");
exit($funn === [] ? 0 : 1);
