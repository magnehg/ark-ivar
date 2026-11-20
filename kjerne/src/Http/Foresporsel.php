<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

use Bivrost\Kjerne\Feil\HttpFeil;

/**
 * En innkommende HTTP-forespørsel, uten globale variabler etter at den er laget (testbar). fraGlobale() leser
 * $_SERVER, $_GET, $_COOKIE og php://input én gang.
 */
final class Foresporsel
{
    /**
     * @param array<string, string> $hoder Med små bokstaver i navnene.
     * @param array<string, string> $query
     * @param array<string, string> $kapsler
     */
    public function __construct(
        public readonly string $metode,
        public readonly string $sti,
        public readonly array $hoder = [],
        public readonly array $query = [],
        public readonly array $kapsler = [],
        public readonly string $kropp = '',
        public readonly string $ip = '127.0.0.1',
        public readonly bool $https = false,
        public readonly string $vert = 'localhost',
        public readonly string $serverIp = '127.0.0.1',
    ) {
    }

    public static function fraGlobale(): self
    {
        $hoder = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_string($v) && str_starts_with($k, 'HTTP_')) {
                $hoder[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $n) {
            if (is_string($_SERVER[$k] ?? null)) {
                $hoder[$n] = $_SERVER[$k];
            }
        }
        // Authorization kommer ikke alltid fram når PHP kjører som CGI; .htaccess i Bivrost setter REDIRECT_HTTP_AUTHORIZATION.
        if (!isset($hoder['authorization']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
            $hoder['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $sti = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $kapsler = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $kapsler[$k] = $v;
            }
        }
        $https = (is_string($_SERVER['HTTPS'] ?? null) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443');
        $metode = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $vert = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : '';
        $serverIp = is_string($_SERVER['SERVER_ADDR'] ?? null) ? $_SERVER['SERVER_ADDR'] : '';
        // PHPs innebygde webserver (bare utvikling og tester) setter ikke SERVER_ADDR, men SERVER_NAME er adressen den lytter på.
        if ($serverIp === '' && PHP_SAPI === 'cli-server' && is_string($_SERVER['SERVER_NAME'] ?? null)) {
            $serverIp = $_SERVER['SERVER_NAME'];
        }
        // X-Forwarded-Proto og lignende brukes aldri: HTTPS avgjøres bare av webserverens egen forbindelse (BIV-AUT-09).
        // Over HTTP leses kroppen bare når alt er loopback (lokal utvikling med BIVROST_TILLAT_HTTP_LOKALT, som Kjerne
        // sjekker); ellers tas en hemmelighet sendt over HTTP aldri imot.
        $f = new self($metode, $sti, $hoder, $query, $kapsler, '', $ip, $https, $vert, $serverIp);
        if (!$https && !$f->loopback()) {
            return $f;
        }

        return new self($metode, $sti, $hoder, $query, $kapsler, (string) file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024), $ip, $https, $vert, $serverIp);
    }

    public function hode(string $navn): ?string
    {
        return $this->hoder[strtolower($navn)] ?? null;
    }

    public function kapsel(string $navn): ?string
    {
        return $this->kapsler[$navn] ?? null;
    }

    /** Opphavet til nettstedet selv, f.eks. https://gerd.example.no. */
    public function opphav(): string
    {
        return ($this->https ? 'https' : 'http') . '://' . $this->vert;
    }

    /**
     * JSON-kroppen som en matrise. Kaster 400 for ugyldig JSON og 415 når innholdstypen ikke er JSON.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->kropp === '') {
            return [];
        }
        $type = strtolower((string) $this->hode('content-type'));
        if (!str_starts_with($type, 'application/json')) {
            throw new HttpFeil(415, 'feil_innholdstype', 'bivrost.feil.http.innholdstype');
        }
        try {
            $j = json_decode($this->kropp, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpFeil(400, 'ugyldig_json', 'bivrost.feil.http.json');
        }
        if (!is_array($j) || array_is_list($j) && $j !== []) {
            throw new HttpFeil(400, 'ugyldig_json', 'bivrost.feil.http.json');
        }
        $ut = [];
        foreach ($j as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return $ut;
    }

    /** En streng fra JSON-kroppen, eller 422 når den mangler eller er for lang. */
    public function tekst(string $felt, int $maks = 1000, bool $kreves = true): ?string
    {
        $v = $this->json()[$felt] ?? null;
        if ($v === null && !$kreves) {
            return null;
        }
        if (!is_string($v) || ($kreves && $v === '') || mb_strlen($v, 'UTF-8') > $maks || !mb_check_encoding($v, 'UTF-8')) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => $felt]);
        }

        return $v;
    }

    /** Bearer-tokenet fra Authorization (RFC 6750), eller null. */
    public function bearer(): ?string
    {
        $h = $this->hode('authorization');
        if ($h === null || preg_match('/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)$/D', trim($h), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** Om både klienten, serveren og vertsnavnet er denne maskinen (loopback). */
    public function loopback(): bool
    {
        $ip = static fn (string $a): bool => $a === '::1' || str_starts_with($a, '127.');
        $vert = strtolower((string) preg_replace('/:\d+$/D', '', $this->vert));

        return $ip($this->ip) && $ip($this->serverIp) && in_array($vert, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public function endrer(): bool
    {
        return !in_array($this->metode, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
