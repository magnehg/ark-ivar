<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

use Bivrost\Kjerne\Sprak\Tekster;

/**
 * Feilsider uten lekkasjer (ASVS V7.4.1, BIV-SIK-05): en kort tekst på brukerens språk og korrelasjons-ID-en, aldri
 * stakkspor, SQL, stier eller hemmeligheter. Detaljene står i serverens feillogg med den samme ID-en.
 */
final class Feilside
{
    public static function html(Tekster $t, string $sprak, int $status, string $melding, string $korrelasjon): Svar
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $tittel = $t->tekst('bivrost.feilside.tittel', ['status' => $status], $sprak);
        $html = '<!doctype html><html lang="' . $e($sprak === 'en' ? 'en' : ($sprak === 'nn' ? 'nn' : 'nb')) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($tittel) . '</title>'
            . '<link rel="stylesheet" href="/bivrost/ressurser/stil/bivrost.css"></head>'
            . '<body class="bivrost-feilside"><main><h1>' . $e($tittel) . '</h1><p>' . $e($melding) . '</p>'
            . '<p class="bivrost-dempet">' . $e($t->tekst('bivrost.feilside.korrelasjon', ['id' => $korrelasjon], $sprak)) . '</p></main></body></html>';

        return Svar::html($html, $status);
    }
}
