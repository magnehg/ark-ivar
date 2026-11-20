<?php

declare(strict_types=1);

/*
 * Inngangen til Ark-Ivar: alle forespørsler som ikke er en fil i denne mappen, kommer hit (.htaccess). Bivrost
 * Kjerne tar seg av installasjonen, økten og sikkerheten og gir resten til appens ruter (ArkIvar\App).
 */

require __DIR__ . '/../autoload.php';

use ArkIvar\App;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Kjerne;

// PHPs innebygde webserver (lokal utvikling) leverer filene i mappen selv.
if (PHP_SAPI === 'cli-server') {
    $adresse = $_SERVER['REQUEST_URI'] ?? '/';
    $sti = is_string($adresse) ? parse_url($adresse, PHP_URL_PATH) : null;
    if (is_string($sti) && $sti !== '/' && !str_contains($sti, '..') && is_file(__DIR__ . $sti) && !str_ends_with($sti, '.php')) {
        return false;
    }
}

$kjerne = Kjerne::fraKonfig(App::konfig());
$app = new App($kjerne);
$kjerne->handter(Foresporsel::fraGlobale(), $app->ruter(...))->send();
