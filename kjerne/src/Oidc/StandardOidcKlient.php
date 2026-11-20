<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oidc;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Tilgang\Urn;
use Bivrost\Vendor\Firebase\JWT\JWK;
use Bivrost\Vendor\Firebase\JWT\JWT;
use Bivrost\Kjerne\Http\Guzzle;
use Bivrost\Kjerne\Http\HttpTransport;
use Bivrost\Vendor\GuzzleHttp\ClientInterface;
use Bivrost\Vendor\League\OAuth2\Client\Provider\GenericProvider;
use Bivrost\Vendor\League\OAuth2\Client\Token\AccessToken;
use Psr\Clock\ClockInterface;

/**
 * OIDC-klienten mot Bivrost Sentral, bygget på velprøvde biblioteker: league/oauth2-client (autorisasjonskodeflyt,
 * PKCE S256, tokenbytte) og firebase/php-jwt (signatur og tidskontroll av JWT med nøklene fra JWKS). Bivrost skriver
 * ingen egen kryptografi; her kontrolleres bare kravene i OpenID Connect Core 1.0 § 3.1.3.7 som bibliotekene ikke
 * kjenner: utsteder, mottaker (aud/azp), nonce og algoritmelisten.
 *
 * Oppdagelsesdokumentet (/.well-known/openid-configuration) og nøklene hentes ved behov og holdes i minnet for
 * forespørselen; en ukjent nøkkel-ID gir én ny henting av nøklene (nøkkelbytte hos Sentral).
 */
final class StandardOidcKlient implements OidcKlient
{
    private const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';
    private const TILSTAND_SEKUNDER = 600;

    /** @var array<string, mixed>|null */
    private ?array $oppdagelse = null;

    /** @var array<string, mixed>|null */
    private ?array $nokler = null;

    private readonly ClientInterface $http;

    /**
     * @param HttpTransport|null $transport Bare for tester (en etterlignet Sentral): forespørslene går da gjennom
     *     transporten i stedet for ut på nettet. Klienten er alltid Guzzle med korte tidsavbrudd (10 s, tilkobling
     *     5 s), så en Sentral som ikke svarer, ikke holder forespørselen til webhotellets grense på 30 s (BIV-PLT-02).
     */
    public function __construct(
        private readonly OidcOppsett $oppsett,
        private readonly ClockInterface $klokke,
        ?HttpTransport $transport = null,
    ) {
        $this->http = Guzzle::klient(10, 5, $transport);
    }

    /** @return array<string, mixed> */
    private function oppdagelse(): array
    {
        if ($this->oppdagelse === null) {
            $d = $this->hentJson($this->oppsett->utsteder . '/.well-known/openid-configuration');
            if (($d['issuer'] ?? null) !== $this->oppsett->utsteder) {
                throw new OidcFeil('bivrost.feil.oidc.oppdagelse');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
                if (!is_string($d[$k] ?? null) || !OidcOppsett::trygg($d[$k])) {
                    throw new OidcFeil('bivrost.feil.oidc.oppdagelse');
                }
            }
            $this->oppdagelse = $d;
        }

        return $this->oppdagelse;
    }

    private function adresse(string $nokkel): string
    {
        $v = $this->oppdagelse()[$nokkel] ?? null;

        return is_string($v) ? $v : '';
    }

    /** @return array<string, mixed> */
    private function hentJson(string $url): array
    {
        try {
            $svar = $this->http->request('GET', $url, ['headers' => ['Accept' => 'application/json']]);
        } catch (\Throwable $e) {
            throw new OidcFeil('bivrost.feil.oidc.utilgjengelig', [], $e);
        }
        if ($svar->getStatusCode() !== 200) {
            throw new OidcFeil('bivrost.feil.oidc.utilgjengelig');
        }
        $kropp = (string) $svar->getBody();
        if (strlen($kropp) > 512 * 1024) {
            throw new OidcFeil('bivrost.feil.oidc.utilgjengelig');
        }
        $j = json_decode($kropp, true);
        if (!is_array($j)) {
            throw new OidcFeil('bivrost.feil.oidc.utilgjengelig');
        }
        $ut = [];
        foreach ($j as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return $ut;
    }

    private function leverandor(): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $this->oppsett->klientId,
            'clientSecret' => $this->oppsett->klientHemmelighet,
            'redirectUri' => $this->oppsett->tilbake,
            'urlAuthorize' => $this->adresse('authorization_endpoint'),
            'urlAccessToken' => $this->adresse('token_endpoint'),
            'urlResourceOwnerDetails' => $this->adresse('userinfo_endpoint'),
            'scopes' => $this->oppsett->omfang,
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], ['httpClient' => $this->http]);
    }

    /**
     * Lager adressen til Sentral med nye tilfeldige state og nonce og en PKCE-verifikator (S256), BIV-AUT-01. Hver
     * innlogging får egne verdier, så en adresse som lekker, ikke kan brukes til en annen innlogging.
     */
    public function start(): OidcStart
    {
        $p = $this->leverandor();
        $state = Tilfeldig::token();
        $nonce = Tilfeldig::token();
        $url = $p->getAuthorizationUrl(['state' => $state, 'nonce' => $nonce, 'response_type' => 'code']);
        $verifikator = (string) $p->getPkceCode();

        return new OidcStart($url, ['state' => $state, 'nonce' => $nonce, 'verifikator' => $verifikator, 'tid' => $this->klokke->now()->getTimestamp()]);
    }

    /**
     * Fullfører innloggingen (BIV-AUT-01, BIV-AUT-02): state må være lik den i økten og ikke eldre enn 10 minutter,
     * koden byttes med PKCE-verifikatoren, og ID-tokenet valideres (signatur fra JWKS, utsteder, mottaker/azp, tid) før
     * nonce kontrolleres. Rekkefølgen gjør at ingenting fra Sentral brukes før tokenet er kontrollert.
     *
     * @param array<string, string> $query
     * @param array{state: string, nonce: string, verifikator: string, tid: int} $tilstand
     */
    public function fullfor(array $query, array $tilstand): Identitet
    {
        if (isset($query['error'])) {
            throw new OidcFeil('bivrost.feil.oidc.avvist');
        }
        $state = $query['state'] ?? '';
        $kode = $query['code'] ?? '';
        if ($state === '' || !hash_equals($tilstand['state'], $state) || $kode === '') {
            throw new OidcFeil('bivrost.feil.oidc.tilstand');
        }
        if ($this->klokke->now()->getTimestamp() - $tilstand['tid'] > self::TILSTAND_SEKUNDER) {
            throw new OidcFeil('bivrost.feil.oidc.tilstand');
        }
        $p = $this->leverandor();
        $p->setPkceCode($tilstand['verifikator']);
        try {
            $token = $p->getAccessToken('authorization_code', ['code' => $kode]);
        } catch (\Throwable $e) {
            throw new OidcFeil('bivrost.feil.oidc.token', [], $e);
        }
        if (!$token instanceof AccessToken) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        $id = $token->getValues()['id_token'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        $c = $this->valider($id);
        if (!is_string($c['nonce'] ?? null) || !hash_equals($tilstand['nonce'], $c['nonce'])) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }

        return $this->identitet($c, $token->getToken(), $id);
    }

    /**
     * Kontrollerer signaturen, tiden, utstederen og mottakeren, og gir påstandene.
     *
     * @return array<string, mixed>
     */
    private function valider(string $jwt): array
    {
        $deler = explode('.', $jwt);
        $hode = count($deler) === 3 ? json_decode(JWT::urlsafeB64Decode($deler[0]), true) : null;
        if (!is_array($hode) || !in_array($hode['alg'] ?? null, OidcOppsett::ALGORITMER, true)) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        $kid = is_string($hode['kid'] ?? null) ? $hode['kid'] : null;
        JWT::$leeway = 60;
        JWT::$timestamp = $this->klokke->now()->getTimestamp();
        try {
            $nokler = $this->nokler(false);
            if ($kid !== null && !isset($nokler[$kid])) {
                $nokler = $this->nokler(true);
            }
            $c = (array) JWT::decode($jwt, $nokler);
        } catch (OidcFeil $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OidcFeil('bivrost.feil.oidc.token', [], $e);
        } finally {
            JWT::$timestamp = null;
        }
        $c = json_decode(json_encode($c, JSON_THROW_ON_ERROR), true);
        if (!is_array($c)) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        $aud = $c['aud'] ?? null;
        $aud = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (($c['iss'] ?? null) !== $this->oppsett->utsteder || !in_array($this->oppsett->klientId, $aud, true)) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        if (count($aud) > 1 && ($c['azp'] ?? null) !== $this->oppsett->klientId) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        if (!is_string($c['sub'] ?? null) || $c['sub'] === '' || !is_int($c['iat'] ?? null)) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        $ut = [];
        foreach ($c as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return $ut;
    }

    /** @return array<string, \Bivrost\Vendor\Firebase\JWT\Key> */
    private function nokler(bool $paNytt): array
    {
        if ($this->nokler === null || $paNytt) {
            $this->nokler = $this->hentJson($this->adresse('jwks_uri'));
        }
        try {
            return JWK::parseKeySet($this->nokler, 'RS256');
        } catch (\Throwable $e) {
            throw new OidcFeil('bivrost.feil.oidc.nokler', [], $e);
        }
    }

    /** @param array<string, mixed> $c */
    private function identitet(array $c, ?string $tilgangstoken, ?string $idToken): Identitet
    {
        $urner = [];
        foreach (is_array($c['roles'] ?? null) ? $c['roles'] : [] as $u) {
            if (is_string($u) && Urn::gjelderApp($u, $this->oppsett->app)) {
                $urner[] = $u;
            }
        }
        $pref = is_array($c['bivrost_preferanser'] ?? null) ? $c['bivrost_preferanser'] : [];
        if (is_string($c['locale'] ?? null)) {
            $pref['locale'] = $c['locale'];
        }
        if (is_string($c['zoneinfo'] ?? null)) {
            $pref['timezone'] = $c['zoneinfo'];
        }
        $p = [];
        foreach ($pref as $k => $v) {
            $p[(string) $k] = $v;
        }
        $navn = is_string($c['name'] ?? null) && $c['name'] !== '' ? $c['name'] : (is_string($c['email'] ?? null) ? $c['email'] : Felt::tekst($c['sub']));

        return new Identitet(
            Felt::tekst($c['sub']),
            is_string($c['sid'] ?? null) ? $c['sid'] : null,
            $navn,
            is_string($c['email'] ?? null) ? $c['email'] : null,
            ($c['email_verified'] ?? false) === true,
            is_string($c['title'] ?? null) ? $c['title'] : null,
            array_values(array_unique($urner)),
            $p,
            $tilgangstoken,
            $idToken,
        );
    }

    /**
     * Validerer et utloggingstoken fra Sentral (OpenID Connect Back-Channel Logout 1.0 § 2.6, BIV-AUT-03): samme
     * signatur- og mottakerkontroll som ID-tokenet, hendelsen backchannel-logout må finnes, nonce må mangle (så et
     * ID-token ikke kan brukes som utloggingstoken), og sid eller sub og jti må være med. Kalleren lagrer jti
     * (bivrost_jti), så tokenet ikke kan brukes to ganger.
     *
     * @return array{sid: ?string, sub: ?string, jti: string, utloper: int}
     */
    public function backchannel(string $utloggingstoken): array
    {
        $c = $this->valider($utloggingstoken);
        $hendelser = $c['events'] ?? null;
        if (!is_array($hendelser) || !array_key_exists(self::LOGOUT_EVENT, $hendelser) || array_key_exists('nonce', $c)) {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        $sid = is_string($c['sid'] ?? null) ? $c['sid'] : null;
        $sub = is_string($c['sub'] ?? null) ? $c['sub'] : null;
        $jti = $c['jti'] ?? null;
        if (($sid === null && $sub === null) || !is_string($jti) || $jti === '') {
            throw new OidcFeil('bivrost.feil.oidc.token');
        }
        $iat = is_int($c['iat'] ?? null) ? $c['iat'] : 0;
        $exp = is_int($c['exp'] ?? null) ? $c['exp'] : $iat + 600;

        return ['sid' => $sid, 'sub' => $sub, 'jti' => $jti, 'utloper' => $exp];
    }

    /**
     * Adressen for RP-initiert utlogging hos Sentral (OpenID Connect RP-Initiated Logout 1.0, BIV-AUT-03), eller null
     * når Sentral ikke har end_session_endpoint, ikke svarer eller gir en adresse som ikke er trygg. Null er ikke en
     * feil: brukeren er da logget ut av appen, men ikke av Sentral.
     */
    public function utloggingsadresse(string $etterAdresse, ?string $idTokenHint = null): ?string
    {
        try {
            $ende = $this->adresse('end_session_endpoint');
        } catch (\Throwable) {
            return null;
        }
        if ($ende === '' || !OidcOppsett::trygg($ende)) {
            return null;
        }
        $q = ['client_id' => $this->oppsett->klientId, 'post_logout_redirect_uri' => $etterAdresse];
        if ($idTokenHint !== null) {
            $q['id_token_hint'] = $idTokenHint;
        }

        return $ende . (str_contains($ende, '?') ? '&' : '?') . http_build_query($q);
    }
}
