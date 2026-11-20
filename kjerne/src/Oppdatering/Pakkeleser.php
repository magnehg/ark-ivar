<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Kontrollerer en nedlastet versjonspakke og pakker den ut i en arbeidsmappe (BIV-DRF-06, BIV-DRF-07, DRF-11).
 * Ingenting i installasjonen endres her. Pakken avvises helt (ikke bare filen) når den har:
 *  - en fil utenfor pakkens mappe, et navn med .., \, kontrolltegn eller ugyldig UTF-8, eller samme navn to ganger
 *  - en symbolsk lenke, .env, eller serveroppsett (.htaccess, web.config, .user.ini, php.ini) som pakken ikke har lov til
 *  - for mange filer, en for stor fil eller for mye til sammen (zip-bombe)
 *  - et manifest som ikke stemmer med filene (SHA-256 for hver fil), eller en versjon.json som ikke svarer til taggen
 */
final class Pakkeleser
{
    public const MAKS_FILER = 20000;
    public const MAKS_FIL = 50 * 1024 * 1024;
    public const MAKS_UTPAKKET = 400 * 1024 * 1024;
    private const OPPSETT = ['.htaccess', 'web.config', '.user.ini', 'php.ini'];

    /**
     * @return array{versjon: array<string, mixed>, filer: array<string, string>}
     */
    public static function pakkUt(Pakke $p, string $zipFil, string $tag, string $versjon, string $arbeid): array
    {
        $z = new \ZipArchive();
        if ($z->open($zipFil, \ZipArchive::RDONLY) !== true) {
            throw new BivrostFeil('bivrost.feil.oppdatering.pakke');
        }
        try {
            if ($z->numFiles > self::MAKS_FILER || $z->numFiles < 2) {
                throw new BivrostFeil('bivrost.feil.oppdatering.pakke');
            }
            $filer = [];
            $sum = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i, \ZipArchive::FL_ENC_RAW);
                $rel = $z->getNameIndex($i, \ZipArchive::FL_ENC_RAW);
                if (!is_array($st) || !is_string($rel)) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.pakke');
                }
                if (str_ends_with($rel, '/')) {
                    continue;
                }
                self::sjekkNavn($p, $rel);
                $os = 0;
                $attr = 0;
                $z->getExternalAttributesIndex($i, $os, $attr);
                if ($os === \ZipArchive::OPSYS_UNIX && is_int($attr) && (($attr >> 16) & 0170000) === 0120000) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.pakke_lenke', ['fil' => $rel]);
                }
                if (isset($filer[$rel])) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.pakke_dobbel', ['fil' => $rel]);
                }
                $str = $st['size'];
                $sum += $str;
                if ($str > self::MAKS_FIL || $sum > self::MAKS_UTPAKKET) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.pakke_stor');
                }
                $mal = $arbeid . '/' . $rel;
                if (!is_dir(dirname($mal)) && !@mkdir(dirname($mal), 0755, true) && !is_dir(dirname($mal))) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.arbeid');
                }
                self::skrivStrom($z, $i, $mal, $str);
                $filer[$rel] = (string) hash_file('sha256', $mal);
            }
        } finally {
            $z->close();
        }
        $vj = self::json($arbeid . '/' . $p->versjonsfil());
        if (($vj['pakke'] ?? null) !== $p->id || ($vj['versjon'] ?? null) !== $versjon || ($vj['tag'] ?? null) !== $tag) {
            throw new BivrostFeil('bivrost.feil.oppdatering.versjonsfil');
        }
        $mf = self::json($arbeid . '/' . $p->manifest());
        $liste = $mf['filer'] ?? null;
        if (!is_array($liste)) {
            throw new BivrostFeil('bivrost.feil.oppdatering.manifest');
        }
        $forventet = $filer;
        unset($forventet[$p->manifest()]);
        $iManifest = [];
        foreach ($liste as $rel => $sha) {
            if (is_string($rel) && is_string($sha)) {
                $iManifest[$rel] = strtolower($sha);
            }
        }
        ksort($forventet);
        ksort($iManifest);
        if ($forventet !== $iManifest) {
            throw new BivrostFeil('bivrost.feil.oppdatering.manifest');
        }

        return ['versjon' => $vj, 'filer' => $filer];
    }

    public static function sjekkNavn(Pakke $p, string $rel): void
    {
        $ugyldig = $rel === '' || !mb_check_encoding($rel, 'UTF-8') || preg_match('/[\x00-\x1F\x7F\\\\:*?"<>|]/', $rel) === 1
            || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.?(/|$)#', $rel) === 1 || str_contains($rel, '//');
        if ($ugyldig || !str_starts_with($rel, $p->mappe)) {
            throw new BivrostFeil('bivrost.feil.oppdatering.pakke_navn', ['fil' => mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $rel), 0, 200)]);
        }
        $base = strtolower(basename($rel));
        if ($base === '.env' || str_starts_with($base, '.env.') || (in_array($base, self::OPPSETT, true) && !in_array($rel, $p->oppsettfiler, true))) {
            throw new BivrostFeil('bivrost.feil.oppdatering.pakke_oppsett', ['fil' => $rel]);
        }
    }

    private static function skrivStrom(\ZipArchive $z, int $i, string $mal, int $storrelse): void
    {
        $inn = $z->getStreamIndex($i);
        $ut = @fopen($mal, 'xb');
        if ($inn === false || $ut === false) {
            throw new BivrostFeil('bivrost.feil.oppdatering.arbeid');
        }
        $n = 0;
        try {
            while (!feof($inn)) {
                $bit = fread($inn, 65536);
                if ($bit === false) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.pakke');
                }
                $n += strlen($bit);
                if ($n > $storrelse || fwrite($ut, $bit) !== strlen($bit)) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.pakke');
                }
            }
        } finally {
            fclose($inn);
            fclose($ut);
        }
        if ($n !== $storrelse) {
            @unlink($mal);
            throw new BivrostFeil('bivrost.feil.oppdatering.pakke');
        }
    }

    /** @return array<string, mixed> */
    public static function json(string $fil): array
    {
        $j = is_file($fil) ? json_decode((string) file_get_contents($fil, false, null, 0, 8 * 1024 * 1024), true) : null;
        if (!is_array($j)) {
            throw new BivrostFeil('bivrost.feil.oppdatering.versjonsfil');
        }
        $ut = [];
        foreach ($j as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return $ut;
    }
}
