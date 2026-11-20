<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sikkerhetskopi;

/**
 * Vern mot visning fra nettet for driftsmappene, i tilfelle webroten er satt opp feil (Apache og IIS). Det
 * egentlige vernet er at mappene ligger utenfor webroten (BIV-DRF-01); dette er et ekstra lag.
 */
final class Mappevern
{
    public static function sikre(string $mappe): void
    {
        if (!is_dir($mappe)) {
            return;
        }
        if (!is_file("$mappe/.htaccess")) {
            @file_put_contents("$mappe/.htaccess", "# Bivrost: ingenting her skal kunne hentes fra nettet.\nRequire all denied\n");
        }
        if (!is_file("$mappe/web.config")) {
            @file_put_contents("$mappe/web.config", "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<configuration><system.webServer>"
                . "<authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n");
        }
    }
}
