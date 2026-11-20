<?php

declare(strict_types=1);

// Utviklingsverktøyene og de uprefiksede kopiene av de samme bibliotekene Kjerne bruker (Composers vanlige vendor/)
// lastes sammen med Kjernens prefiksede avhengigheter (BIV-B-024). Testene kjører dermed alltid med begge i samme
// prosess, slik det blir når en app har egne versjoner av bibliotekene.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../autoload.php';
