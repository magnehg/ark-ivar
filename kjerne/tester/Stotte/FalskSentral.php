<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Vendor\Firebase\JWT\JWT;
use Bivrost\Kjerne\Http\HttpTransport;

/**
 * En etterlignet Bivrost Sentral (OIDC-leverandør) for testene: oppdagelsesdokument, JWKS og tokenendepunkt, med
 * en RSA-nøkkel laget for testen. Påstandene i ID-tokenet kan overstyres per test.
 */
final class FalskSentral implements HttpTransport
{
    public const UTSTEDER = 'https://login.test';

    public string $privat;

    /** @var array<string, mixed> */
    public array $jwk;

    /** @var array<string, mixed> Ekstra eller overstyrte påstander i neste ID-token. */
    public array $paastander = [];

    public string $kid = 'n1';
    public string $alg = 'RS256';
    public ?string $sisteVerifikator = null;
    public int $jwksHentet = 0;

    public function __construct(private readonly FastKlokke $klokke)
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($k === false) {
            throw new \RuntimeException('openssl');
        }
        $privat = '';
        openssl_pkey_export($k, $privat);
        $this->privat = is_string($privat) ? $privat : '';
        $d = openssl_pkey_get_details($k);
        $rsa = is_array($d) && is_array($d['rsa'] ?? null) ? $d['rsa'] : [];
        $this->jwk = ['kty' => 'RSA', 'kid' => $this->kid, 'use' => 'sig', 'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode(Felt::tekst($rsa['n'] ?? '')), 'e' => JWT::urlsafeB64Encode(Felt::tekst($rsa['e'] ?? ''))];
    }

    /** @param array<string, mixed> $c */
    public function signer(array $c): string
    {
        return JWT::encode($c, $this->privat, $this->alg, $this->kid);
    }

    /** @return array<string, mixed> */
    public function idPaastander(string $nonce): array
    {
        $t = $this->klokke->now()->getTimestamp();

        return array_merge([
            'iss' => self::UTSTEDER, 'aud' => 'gerd', 'sub' => 'sub-kari', 'sid' => 'sid-1', 'iat' => $t, 'exp' => $t + 300,
            'nonce' => $nonce, 'name' => 'Kari Nordmann', 'email' => 'kari@kommune.no', 'email_verified' => true, 'title' => 'Arkivleder',
            'roles' => ['urn:bivrost:app:gerd:rolle:arkivar', 'urn:bivrost:app:hugr:rolle:leser', 'urn:bivrost:tilgang:drift'],
            'bivrost_preferanser' => ['tema' => 'mose', 'modus' => 'mork', 'oppdatert' => '2026-11-13T09:00:00Z'],
            'locale' => 'nb-NO', 'zoneinfo' => 'Europe/Oslo',
        ], $this->paastander);
    }

    /**
     * Svarer som Sentral ut fra adressen, uten nett og uten Guzzle (Kjernens egen HttpTransport, BIV-B-024).
     *
     * @param array<string, list<string>> $hoder
     * @return array{status: int, hoder: array<string, list<string>|string>, kropp: string}
     */
    public function send(string $metode, string $url, array $hoder, string $kropp): array
    {
        $json = static fn (mixed $d, int $s = 200): array => ['status' => $s, 'hoder' => ['Content-Type' => 'application/json'], 'kropp' => (string) json_encode($d)];

        return match ((string) parse_url($url, PHP_URL_PATH)) {
            '/.well-known/openid-configuration' => $json([
                'issuer' => self::UTSTEDER,
                'authorization_endpoint' => self::UTSTEDER . '/autoriser',
                'token_endpoint' => self::UTSTEDER . '/token',
                'jwks_uri' => self::UTSTEDER . '/jwks',
                'userinfo_endpoint' => self::UTSTEDER . '/userinfo',
                'end_session_endpoint' => self::UTSTEDER . '/logg-ut',
            ]),
            '/jwks' => (function () use ($json): array {
                $this->jwksHentet++;

                return $json(['keys' => [$this->jwk]]);
            })(),
            '/token' => (function () use ($kropp, $json): array {
                parse_str($kropp, $felt);
                $this->sisteVerifikator = is_string($felt['code_verifier'] ?? null) ? $felt['code_verifier'] : null;
                $nonce = is_string($felt['code'] ?? null) ? explode(':', $felt['code'])[1] ?? '' : '';

                return $json(['access_token' => 'tilgang-123', 'token_type' => 'Bearer', 'expires_in' => 300, 'id_token' => $this->signer($this->idPaastander($nonce))]);
            })(),
            default => ['status' => 404, 'hoder' => [], 'kropp' => ''],
        };
    }
}
