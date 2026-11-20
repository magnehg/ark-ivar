<?php

/**
 * En liten app for ende-til-ende-testen over ekte HTTP (PHPs webserver): slik en app kobler Kjerne inn i sin
 * public/index.php. EKSEMPEL_ROT er installasjonsroten testen har laget.
 */

declare(strict_types=1);

// Appens egne avhengigheter (her Composers vanlige vendor/, med uprefiksede kopier av de samme bibliotekene) og så
// Kjerne, slik appene gjør det (BIV-B-024).
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../autoload.php';

use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Kjerne;

$rot = (string) getenv('EKSEMPEL_ROT');
$kjerne = Kjerne::fraKonfig([
    'app' => 'eksempel',
    'appnavn' => 'Eksempelappen',
    'rot' => $rot,
    'roller' => [['navn' => 'leser', 'tekst' => ['nb' => 'Leser', 'en' => 'Reader']]],
    'tabellprefikser' => ['eksempel_'],
]);
$kjerne->handter(Foresporsel::fraGlobale(), static function (Foresporsel $f, Kjerne $k): ?Svar {
    if ($f->sti === '/hei') {
        $i = $k->krev('urn:bivrost:tilgang:drift');

        return Svar::json(['hei' => $i->aktor->navn]);
    }

    return null;
})->send();
