<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

/**
 * Sikkerhetshodene på alle svar fra Kjerne og appen (SIK-01, ASVS V14.4, BIV-SIK-01). CSP tillater bare ressurser fra
 * nettstedet selv (ingen CDN, PLT-08), ingen innebygde skript, ingen innramming. HSTS bare over HTTPS.
 * Et svar som alt har et hode (f.eks. en strengere CSP for en side), beholder det.
 */
final class Sikkerhetshoder
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
        . "connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; "
        . 'upgrade-insecure-requests';

    public static function sett(Svar $svar, bool $https): Svar
    {
        $hoder = [
            'Content-Security-Policy' => $https ? self::CSP : str_replace('; upgrade-insecure-requests', '', self::CSP),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
        ];
        if ($https) {
            $hoder['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($hoder as $k => $v) {
            if ($svar->hode($k) === null) {
                $svar->settHode($k, $v);
            }
        }

        return $svar;
    }
}
