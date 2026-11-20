<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oidc;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Konfig\Env;

/**
 * Oppsettet for innloggingen mot Sentral, fra .env (BIV-SIK-03):
 *   BIVROST_SENTRAL_URL        utstederen (issuer), f.eks. https://login.magnehg.no
 *   BIVROST_KLIENT_ID          appens klient-ID hos Sentral
 *   BIVROST_KLIENT_HEMMELIGHET klienthemmeligheten (client_secret_basic); aldri i git eller til nettleseren
 *   BIVROST_TILBAKE_URL        redirect_uri, f.eks. https://gerd.example.no/bivrost/innlogging/tilbake
 * Bare HTTPS, unntatt mot denne maskinen (tester).
 */
final class OidcOppsett
{
    /** Algoritmene som godtas for ID-tokenet (aldri «none» eller HS*). */
    public const ALGORITMER = ['RS256', 'PS256', 'ES256', 'EdDSA'];

    /** @param list<string> $omfang */
    public function __construct(
        public readonly string $utsteder,
        public readonly string $klientId,
        public readonly string $klientHemmelighet,
        public readonly string $tilbake,
        public readonly string $app,
        public readonly array $omfang = ['openid', 'profile', 'email', 'bivrost'],
    ) {
        foreach ([$utsteder, $tilbake] as $u) {
            if (!self::trygg($u)) {
                throw new BivrostFeil('bivrost.feil.oidc.adresse');
            }
        }
        if ($klientId === '') {
            throw new BivrostFeil('bivrost.feil.oidc.klient');
        }
    }

    /**
     * Oppsettet fra .env, eller null når BIVROST_SENTRAL_URL ikke er satt. Null betyr at innloggingen mot Sentral er
     * av og at bare nødinnloggingen virker (BIV-AUT-04), slik at en app kan installeres og driftes før Sentral finnes
     * (fase 2). Er adressen satt, kreves BIVROST_KLIENT_ID og BIVROST_TILBAKE_URL, og en feil stopper den første
     * forespørselen som trenger innloggingen mot Sentral (f.eks. GET /bivrost/api/okt eller /bivrost/innlogging, via
     * Kjerne::oidc()), før nettleseren sendes til Sentral, i stedet for midt i en innlogging.
     */
    public static function fraEnv(Env $env, string $app): ?self
    {
        $url = $env->hent('BIVROST_SENTRAL_URL');
        if ($url === null || $url === '') {
            return null;
        }

        return new self(
            rtrim($url, '/'),
            $env->krev('BIVROST_KLIENT_ID'),
            $env->hent('BIVROST_KLIENT_HEMMELIGHET', '') ?? '',
            $env->krev('BIVROST_TILBAKE_URL'),
            $app,
        );
    }

    /**
     * Om adressen kan brukes mot Sentral: bare https, med http bare mot denne maskinen (tester). Brukes også på
     * adressene i oppdagelsesdokumentet, så en Sentral som er satt opp feil eller er kompromittert, ikke kan sende
     * tokenbyttet eller nøklene over ukryptert HTTP (BIV-AUT-02, BIV-SIK-06).
     */
    public static function trygg(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return false;
        }

        return $p['scheme'] === 'https' || ($p['scheme'] === 'http' && in_array($p['host'], ['127.0.0.1', 'localhost'], true));
    }
}
