<?php

/**
 * Lager et nøkkelpar for signering av versjonspakkene (Ed25519, BIV-DRF-07): php bin/lag-signeringsnokkel.php
 * Den hemmelige nøkkelen legges BARE i hemmeligheten BIVROST_SIGNERINGSNOKKEL i GitHub-miljøet «utgivelse» (aldri i
 * git, på serveren, i loggen, i en samtale eller som hemmelighet på repo-nivå). Den offentlige skrives i kjerne/signeringsnokkel.offentlig: den følger med pakken, og
 * installasjonene klarerer den første gang (BIV-DRF-14). Ved bytte av nøkkel, se README («Bytte av utgivernøkkel»).
 */

declare(strict_types=1);

// Bare libsodium fra PHP trengs, ikke avhengighetene: skriptet virker rett etter en klone (f.eks. i GitHub Codespaces),
// uten composer install og prefiks.sh.
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "PHP mangler utvidelsen sodium.\n");
    exit(1);
}
require __DIR__ . '/../src/Oppdatering/Signatur.php';

$par = Bivrost\Kjerne\Oppdatering\Signatur::nyttNokkelpar();
fwrite(STDOUT, "Offentlig nøkkel (i kjerne/signeringsnokkel.offentlig, eventuelt BIVROST_OFFENTLIG_NOKKEL i .env):\n{$par['offentlig']}\n\n");
fwrite(STDOUT, "Hemmelig nøkkel (BARE som hemmeligheten BIVROST_SIGNERINGSNOKKEL i GitHub-miljøet «utgivelse»):\n{$par['hemmelig']}\n");
