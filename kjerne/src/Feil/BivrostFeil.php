<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Feil;

/**
 * Grunnklassen for feil Bivrost kaster med vilje. Meldingen er en tekstnøkkel i språkfilene (sprak/nb.json) med
 * verdier til plassholderne, så feilen kan vises på brukerens språk og logges på hovedspråket. Meldingen skal aldri
 * inneholde hemmeligheter (passord, nøkler, tokener, databaseoppsett).
 */
class BivrostFeil extends \RuntimeException
{
    /**
     * @param string $tekstnokkel Nøkkelen i språkfilen, f.eks. «feil.oppdatering.sjekksum».
     * @param array<string, scalar|null> $verdier Verdier til plassholderne i teksten.
     */
    public function __construct(
        public readonly string $tekstnokkel,
        public readonly array $verdier = [],
        ?\Throwable $forrige = null,
    ) {
        parent::__construct($tekstnokkel, 0, $forrige);
    }
}
