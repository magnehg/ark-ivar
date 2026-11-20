<?php

declare(strict_types=1);

// Én operasjon i øvingsarkivet i en egen prosess, startet av testene med Bakgrunnsprosess::start(). Argumentet er
// oppdraget som JSON: testdatabasen, klokkeslettet, operasjonen og argumentene til den.
require __DIR__ . '/../bootstrap.php';

$argumenter = $_SERVER['argv'] ?? [];
\ArkIvar\Tester\Stotte\Bakgrunnsprosess::utfor(is_array($argumenter) && is_string($argumenter[1] ?? null) ? $argumenter[1] : '');
