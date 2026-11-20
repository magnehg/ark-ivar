<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Okt;

/**
 * Appens egne brukere (Ark-Ivars tillegg til Bivrost Kjerne, se docs/opphav.md). Bivrost forutsetter at vanlige
 * brukere ligger i Bivrost Sentral; en app som ikke er koblet til Sentral, har brukerne sine selv og logger dem inn
 * med Okt::loggInn() og metoden «app». Kjerne spør denne tjenesten ved hver forespørsel om innloggingen fortsatt
 * gjelder, på samme måte som den kontrollerer de lokale driftsbrukerne.
 */
interface Appbrukere
{
    /**
     * Om innloggingen fortsatt gjelder: brukeren finnes, er aktiv og har samme passordgenerasjon som da økten ble
     * laget (et passordbytte eller en deaktivering avslutter eldre økter). Rollene i økten skal også stemme med
     * brukerens roller nå, så en endret rolle virker fra neste forespørsel.
     */
    public function gyldig(Innlogget $innlogget): bool;
}
