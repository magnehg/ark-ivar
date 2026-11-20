<?php

declare(strict_types=1);

namespace ArkIvar\Epost;

/**
 * E-posten ble ikke tatt imot for sending (webhotellets mail() svarte nei, eller filen kunne ikke skrives).
 * Meldingen er en kort teknisk kode til loggen (mail_avvist, ugyldig_mottaker …), aldri tekst til brukeren.
 */
final class EpostFeil extends \RuntimeException
{
}
