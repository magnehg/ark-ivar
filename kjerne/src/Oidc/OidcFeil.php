<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oidc;

use Bivrost\Kjerne\Feil\BivrostFeil;

/** En innlogging som ikke kan fullføres (feil state, ugyldig token, Sentral svarer ikke). Detaljene logges, ikke vises. */
final class OidcFeil extends BivrostFeil
{
}
