<?php

declare(strict_types=1);

/*
 * Lasteren for Ark-Ivar: Bivrost Kjerne (med sine prefiksede avhengigheter) og appens egne klasser (ArkIvar\… fra
 * src/). Appen har ingen egne avhengigheter, så serveren trenger ikke Composer.
 */

(static function (): void {
    $rot = dirname(__DIR__);
    // bivrost/kjerne/ i en installasjon, kjerne/ i en utviklingskopi av repoet.
    $kjerne = is_file($rot . '/bivrost/kjerne/autoload.php') ? $rot . '/bivrost/kjerne/autoload.php' : $rot . '/kjerne/autoload.php';
    require_once $kjerne;
    $src = __DIR__ . '/src/';
    spl_autoload_register(static function (string $klasse) use ($src): void {
        if (!str_starts_with($klasse, 'ArkIvar\\')) {
            return;
        }
        $fil = $src . str_replace('\\', '/', substr($klasse, strlen('ArkIvar\\'))) . '.php';
        if (is_file($fil)) {
            require $fil;
        }
    });
})();
