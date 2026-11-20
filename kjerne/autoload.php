<?php

declare(strict_types=1);

/*
 * Lasteren for Bivrost Kjerne (BIV-B-024). Appene tar med denne filen, ikke vendor/autoload.php:
 *
 *     require __DIR__ . '/../../bivrost/kjerne/autoload.php';
 *
 * Kjernens avhengigheter har prefikset Bivrost\Vendor\ (laget av skript/prefiks.sh med php-scoper), så appen kan ha
 * egne versjoner av de samme bibliotekene. De ligger i vendor/ i versjonspakken og i vendor-prefikset/ i en
 * utviklingskopi (der vendor/ er Composers vanlige mappe med utviklingsverktøyene). Kjernens egne klasser
 * (Bivrost\Kjerne\…) lastes fra src/.
 */

(static function (): void {
    $prefikset = is_file(__DIR__ . '/vendor-prefikset/autoload.php') ? __DIR__ . '/vendor-prefikset/autoload.php' : __DIR__ . '/vendor/autoload.php';
    if (is_file($prefikset)) {
        require_once $prefikset;
    }
    // Den prefiksede lasteren har et fast navn (prefiks.sh). Uten den er dette Composers vanlige vendor/ i en
    // utviklingskopi, og Bivrost\Vendor\… ville manglet midt i en forespørsel.
    if (!class_exists('ComposerAutoloaderInitBivrostKjerneVendor', false)) {
        throw new RuntimeException('Bivrost Kjerne mangler de prefiksede avhengighetene. Kjør composer install og bash ../skript/prefiks.sh.');
    }
    $src = __DIR__ . '/src/';
    spl_autoload_register(static function (string $klasse) use ($src): void {
        if (!str_starts_with($klasse, 'Bivrost\\Kjerne\\')) {
            return;
        }
        $fil = $src . str_replace('\\', '/', substr($klasse, strlen('Bivrost\\Kjerne\\'))) . '.php';
        if (is_file($fil)) {
            require $fil;
        }
    });
})();
