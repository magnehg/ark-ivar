<?php

declare(strict_types=1);

namespace ArkIvar\Epost;

/**
 * Sender e-post fra Ark-Ivar (koden som bekrefter e-postadressen, og koden for nytt passord). Bak et grensesnitt,
 * så testene og lokal utvikling kan skrive brevene til fil i stedet for å sende dem.
 */
interface Epostsender
{
    /**
     * Sender et brev i ren tekst. Kaster EpostFeil når brevet ikke ble tatt imot for sending.
     */
    public function send(string $til, string $emne, string $tekst): void;
}
