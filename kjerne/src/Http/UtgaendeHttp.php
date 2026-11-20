<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Utgående HTTP med curl for nedlastingen av versjonspakker (BIV-DRF-06). Sikker som standard:
 *  - bare HTTPS (HTTP bare mot denne maskinen, til tester), også etter omdirigering
 *  - omdirigeringer følges for hånd (høyst 5), og Authorization sendes aldri videre til en annen vert
 *  - en øvre grense for størrelsen, både i minnet og til fil (filen slettes ved feil)
 *  - TLS-kontroll er alltid på
 */
final class UtgaendeHttp
{
    private const OMDIRIGERINGER = 5;

    public function __construct(private readonly string $brukeragent = 'Bivrost-Kjerne')
    {
    }

    public static function trygg(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return false;
        }

        return $p['scheme'] === 'https' || ($p['scheme'] === 'http' && in_array($p['host'], ['127.0.0.1', 'localhost'], true));
    }

    /**
     * @param array<string, string> $hoder
     * @return array{status: int, kropp: string}
     */
    public function hent(string $url, array $hoder, int $maks, ?string $tilFil = null): array
    {
        if (!function_exists('curl_init')) {
            throw new BivrostFeil('bivrost.feil.http.curl');
        }
        $vert = parse_url($url, PHP_URL_HOST);
        for ($i = 0; $i <= self::OMDIRIGERINGER; $i++) {
            if (!self::trygg($url)) {
                throw new BivrostFeil('bivrost.feil.http.utrygg_adresse');
            }
            if (parse_url($url, PHP_URL_HOST) !== $vert) {
                unset($hoder['Authorization']);
            }
            [$status, $kropp, $videre] = $this->ett($url, $hoder, $maks, $tilFil);
            if ($status >= 300 && $status < 400 && $videre !== null) {
                $url = $videre;
                continue;
            }

            return ['status' => $status, 'kropp' => $kropp];
        }
        throw new BivrostFeil('bivrost.feil.http.omdirigeringer');
    }

    /**
     * @param array<string, string> $hoder
     * @return array{0: int, 1: string, 2: ?string}
     */
    private function ett(string $url, array $hoder, int $maks, ?string $tilFil): array
    {
        $h = curl_init($url);
        if ($h === false) {
            throw new BivrostFeil('bivrost.feil.http.curl');
        }
        $f = null;
        if ($tilFil !== null) {
            $f = @fopen($tilFil, 'wb');
            if ($f === false) {
                throw new BivrostFeil('bivrost.feil.http.skriv');
            }
        }
        $buf = '';
        $mottatt = 0;
        $forStor = false;
        $skrivefeil = false;
        $lokasjon = null;
        $liste = ['User-Agent: ' . $this->brukeragent];
        foreach ($hoder as $k => $v) {
            $liste[] = "$k: $v";
        }
        curl_setopt_array($h, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => $liste,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linje) use (&$lokasjon): int {
                if (preg_match('/^Location:\s*(\S+)/i', $linje, $m) === 1) {
                    $lokasjon = $m[1];
                }

                return strlen($linje);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $data) use (&$buf, &$mottatt, &$forStor, &$skrivefeil, $f, $maks): int {
                $mottatt += strlen($data);
                if ($mottatt > $maks) {
                    $forStor = true;

                    return 0;
                }
                if ($f !== null) {
                    if (fwrite($f, $data) !== strlen($data)) {
                        $skrivefeil = true;

                        return 0;
                    }
                } else {
                    $buf .= $data;
                }

                return strlen($data);
            },
        ]);
        $ok = curl_exec($h);
        $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        if ($f !== null) {
            fclose($f);
        }
        if ($forStor || $skrivefeil || $ok === false) {
            if ($tilFil !== null) {
                @unlink($tilFil);
            }
            throw new BivrostFeil($forStor ? 'bivrost.feil.http.for_stor' : ($skrivefeil ? 'bivrost.feil.http.skriv' : 'bivrost.feil.http.nettverk'));
        }
        if ($lokasjon !== null && preg_match('#^https?://#i', $lokasjon) !== 1) {
            $p = parse_url($url);
            $lokasjon = (is_array($p) ? ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') : '') . '/' . ltrim($lokasjon, '/');
        }

        return [$status, $buf, $lokasjon];
    }
}
