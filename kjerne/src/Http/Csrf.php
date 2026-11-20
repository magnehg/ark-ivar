<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Okt\Okt;

/**
 * Vern mot forfalskede forespørsler (CSRF, SIK-02, ASVS V13.2.3, BIV-SIK-02) for forespørsler som bruker økten:
 * (1) nøkkelen i økten må stå i hodet X-CSRF (synkroniseringsnøkkel; kan ikke settes av et skjema på en annen side),
 * og (2) Origin (eller Referer) må være nettstedet selv når den er med. Forespørsler med Bearer-token (maskiner)
 * bruker ikke økten og trenger ikke dette.
 */
final class Csrf
{
    public static function nokkel(Okt $okt): string
    {
        $n = $okt->hent('csrf');
        if (!is_string($n) || $n === '') {
            $n = Tilfeldig::token();
            $okt->sett('csrf', $n);
        }

        return $n;
    }

    public static function krev(Foresporsel $f, Okt $okt): void
    {
        $opphav = $f->hode('origin');
        if ($opphav === null && ($ref = $f->hode('referer')) !== null) {
            $p = parse_url($ref);
            $opphav = is_array($p) && isset($p['scheme'], $p['host']) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : 'ugyldig';
        }
        if ($opphav !== null && $opphav !== $f->opphav()) {
            throw new HttpFeil(403, 'csrf', 'bivrost.feil.http.csrf');
        }
        $n = $okt->hent('csrf');
        $gitt = $f->hode('x-csrf');
        if (!is_string($n) || $n === '' || $gitt === null || !hash_equals($n, $gitt)) {
            throw new HttpFeil(403, 'csrf', 'bivrost.feil.http.csrf');
        }
    }
}
