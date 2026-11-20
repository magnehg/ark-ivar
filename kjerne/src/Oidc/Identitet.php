<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oidc;

/**
 * Den innloggede fra ID-tokenet: fast ID hos Sentral (sub), økten hos Sentral (sid), navn, e-post, stilling/tittel
 * (SCIM «title»), URN-ene for rollene og tilgangene (claimet «roles», bare de som gjelder denne appen eller er felles)
 * og brukerens preferanser (claimet «bivrost_preferanser», locale og zoneinfo).
 */
final class Identitet
{
    /**
     * @param list<string> $urner
     * @param array<string, mixed> $preferanser
     */
    public function __construct(
        public readonly string $sub,
        public readonly ?string $sid,
        public readonly string $navn,
        public readonly ?string $epost,
        public readonly bool $epostBekreftet,
        public readonly ?string $tittel,
        public readonly array $urner,
        public readonly array $preferanser,
        public readonly ?string $tilgangstoken = null,
        public readonly ?string $idToken = null,
    ) {
    }
}
