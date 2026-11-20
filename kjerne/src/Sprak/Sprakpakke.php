<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sprak;

/**
 * Manifestet for en språkpakke (BIV-SPR-05, docs/sprakpakker.md, skjema/sprakpakke.schema.json):
 *   {"format": "bivrost-sprakpakke", "formatversjon": 1, "kode": "se", "navn": {"se": "Davvisámegiella", "nb": "Nordsamisk"},
 *    "versjon": "0.1.0", "dekker": ["bivrost-kjerne"], "retning": "ltr"}
 * Koden er en BCP 47-tag (språk, valgfritt skrift og region). En pakke som ikke passer, overses.
 */
final class Sprakpakke
{
    public const KODE = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|\d{3}))?$/D';

    /** @return array{kode: string, navn: array<string, string>, versjon: string}|null */
    public static function les(string $fil): ?array
    {
        $j = json_decode((string) @file_get_contents($fil), true);
        if (!is_array($j) || ($j['format'] ?? null) !== 'bivrost-sprakpakke' || ($j['formatversjon'] ?? null) !== 1) {
            return null;
        }
        $kode = $j['kode'] ?? null;
        $versjon = $j['versjon'] ?? null;
        $navn = $j['navn'] ?? null;
        if (!is_string($kode) || preg_match(self::KODE, $kode) !== 1 || !is_string($versjon) || preg_match('/^\d+\.\d+\.\d+$/D', $versjon) !== 1 || !is_array($navn)) {
            return null;
        }
        $n = [];
        foreach ($navn as $k => $v) {
            if (is_string($k) && is_string($v) && $v !== '') {
                $n[$k] = $v;
            }
        }
        if (!isset($n[$kode])) {
            return null;
        }

        return ['kode' => $kode, 'navn' => $n, 'versjon' => $versjon];
    }
}
