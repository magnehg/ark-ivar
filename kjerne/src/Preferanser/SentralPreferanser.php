<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Preferanser;

/**
 * Kjernens kobling til preferansene hos Bivrost Sentral (BIV-PRF-03). Kontrakten (docs/arkitektur.md):
 *   GET   {Sentral}/scim/v2/Me                               med brukerens tilgangstoken (Bearer)
 *   PATCH {Sentral}/scim/v2/Me  (application/scim+json)      PatchOp «replace» med feltene som endres
 * Sentral er fase 2; testene bruker en etterlignet implementasjon.
 */
interface SentralPreferanser
{
    /** De felles preferansene hos Sentral, eller null når Sentral ikke svarer. */
    public function hent(string $tilgangstoken): ?Preferanser;

    /** Lagrer de felles preferansene hos Sentral. Gir false når Sentral ikke svarer (endringen venter da lokalt). */
    public function lagre(string $tilgangstoken, Preferanser $felles): bool;
}
