<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oidc;

/**
 * Resultatet av OidcKlient::start() (BIV-AUT-01): adressen nettleseren sendes til hos Sentral, og tilstanden som må
 * huskes i økten til nettleseren kommer tilbake. Tilstanden har state (vern mot CSRF i innloggingen), nonce (binder
 * ID-tokenet til denne innloggingen), PKCE-verifikatoren (S256) og tiden, så en gammel innlogging kan avvises.
 * Den ligger bare i økten på serveren og sendes aldri til nettleseren (bivrost_okt.data «oidc»).
 */
final class OidcStart
{
    /**
     * @param string $adresse authorization_endpoint hos Sentral med client_id, redirect_uri, scope, state, nonce og
     *     code_challenge.
     * @param array{state: string, nonce: string, verifikator: string, tid: int} $tilstand Lagres i økten til retur.
     */
    public function __construct(public readonly string $adresse, public readonly array $tilstand)
    {
    }
}
