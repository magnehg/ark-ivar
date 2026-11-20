<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oidc;

/**
 * Innloggingen mot Bivrost Sentral (OpenID Connect, BIV-AUT-01–03). Kjerne er en OIDC-klient (Relying Party) med
 * autorisasjonskodeflyt og PKCE (S256). Grensesnittet skjuler protokollen, så en test kan bruke en etterlignet
 * Sentral, og så Sentral (fase 2) kan byttes ut med en annen OIDC-leverandør uten endringer i appene.
 */
interface OidcKlient
{
    /** Starter innloggingen: adressen nettleseren sendes til og tilstanden som må lagres i økten til den kommer tilbake. */
    public function start(): OidcStart;

    /**
     * Fullfører innloggingen: kontrollerer state, bytter koden (med PKCE-verifikatoren), validerer ID-tokenet (signatur,
     * utsteder, mottaker, tid og nonce) og gir identiteten. Kaster OidcFeil ved alt som ikke stemmer.
     *
     * @param array<string, string> $query Parametrene i adressen nettleseren kom tilbake med.
     * @param array{state: string, nonce: string, verifikator: string, tid: int} $tilstand Fra start().
     */
    public function fullfor(array $query, array $tilstand): Identitet;

    /**
     * Validerer et utloggingstoken (OpenID Connect Back-Channel Logout 1.0) og gir sid og/eller sub og jti.
     *
     * @return array{sid: ?string, sub: ?string, jti: string, utloper: int}
     */
    public function backchannel(string $utloggingstoken): array;

    /** Adressen for utlogging hos Sentral (RP-initiert utlogging), eller null når Sentral ikke tilbyr det. */
    public function utloggingsadresse(string $etterAdresse, ?string $idTokenHint = null): ?string;
}
