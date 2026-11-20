<?php

declare(strict_types=1);

// Appens tester bruker Kjernens utviklingsverktøy (PHPUnit i kjerne/vendor/) og teststøtte
// (Bivrost\Kjerne\Tester\Stotte: testdatabase, klokke og midlertidige mapper), så appen trenger ingen egen vendor/.
require __DIR__ . '/../../kjerne/tester/bootstrap.php';
require __DIR__ . '/../autoload.php';

spl_autoload_register(static function (string $klasse): void {
    if (!str_starts_with($klasse, 'ArkIvar\\Tester\\')) {
        return;
    }
    $fil = __DIR__ . '/' . str_replace('\\', '/', substr($klasse, strlen('ArkIvar\\Tester\\'))) . '.php';
    if (is_file($fil)) {
        require $fil;
    }
});
