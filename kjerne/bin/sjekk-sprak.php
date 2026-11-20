<?php

/**
 * Kontrollerer språkfilene i Bivrost Kjerne (BIV-SPR-08): php bin/sjekk-sprak.php
 * Avslutter med feilkode 1 når noe ikke er i orden. Kjøres i CI.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$rot = dirname(__DIR__);
$funn = Bivrost\Kjerne\Sprak\Sprakkontroll::kjor($rot . '/i18n', 'bivrost.', [$rot . '/src', dirname($rot) . '/grensesnitt/src']);
foreach ($funn as $f) {
    fwrite(STDOUT, $f . "\n");
}
fwrite(STDOUT, $funn === [] ? "Språkfilene er i orden.\n" : "\n" . count($funn) . " funn.\n");
exit($funn === [] ? 0 : 1);
