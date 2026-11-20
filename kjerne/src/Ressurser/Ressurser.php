<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Ressurser;

use Bivrost\Kjerne\Http\Svar;

/**
 * Leverer designpakken fra appens egen server (BIV-GRS-02, PLT-08): stilfilen med tokens, skriftene og ikonene
 * fra grensesnitt/, som følger med i Kjernens versjonspakke under ressurser/grensesnitt/. Ingen CDN, og ingen
 * avhengighet til Sentral, så utseendet virker når Sentral er nede. Bare filer med kjente endelser og navn uten ..
 * sendes; filene ligger utenfor webroten og nås bare gjennom denne klassen.
 */
final class Ressurser
{
    private const TYPER = [
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
    ];

    /**
     * @param string $mappe ressurser/grensesnitt i versjonspakken, eller repoets grensesnitt/ i en utviklingskopi
     *     (Kjerne::ruter velger). Mappen ligger utenfor webroten.
     */
    public function __construct(private readonly string $mappe)
    {
    }

    /**
     * Svaret for en fil i designpakken (BIV-GRS-02, BIV-PLT-04), eller null (404) når stien ikke er en av mappene
     * stil, fonter, ikoner og tokens, har en ukjent endelse eller ikke finnes. Hvitelisten over mapper og endelser
     * og forbudet mot .. hindrer at noe annet enn designpakken kan hentes. Filene kan hurtigbufres i én time med
     * ETag, og SVG får en egen CSP med sandbox, så et ikon aldri kan kjøre skript (BIV-SIK-01).
     */
    public function svar(string $sti): ?Svar
    {
        if (preg_match('#^(stil|fonter|ikoner|tokens)(/[a-z0-9][a-z0-9._-]*)+$#iD', $sti) !== 1 || str_contains($sti, '..')) {
            return null;
        }
        $type = self::TYPER[strtolower(pathinfo($sti, PATHINFO_EXTENSION))] ?? null;
        $fil = $this->mappe . '/' . $sti;
        if ($type === null || !is_file($fil)) {
            return null;
        }
        $innhold = (string) file_get_contents($fil);
        $etag = '"' . substr(hash('sha256', $innhold), 0, 32) . '"';
        $s = new Svar(200, $innhold, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
        ]);
        if ($type === 'image/svg+xml') {
            $s->settHode('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        return $s;
    }
}
