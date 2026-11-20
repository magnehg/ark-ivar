<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Krypto;

/**
 * Tilfeldige verdier fra operativsystemets kryptografiske kilde (random_bytes). Ingen egen kryptografi.
 */
final class Tilfeldig
{
    /** En UUID versjon 4 (RFC 9562). */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-'
            . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    public static function erUuid(string $verdi): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $verdi) === 1;
    }

    /** Et token på $bytes tilfeldige byte, som base64url uten utfylling (til økter, nøkler og CSRF). */
    public static function token(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('Et token må ha minst 16 tilfeldige byte.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * En kode som er lett å lese og skrive av (Crockfords base32 uten I, L, O og U), i grupper på fire med bindestrek.
     * Brukes til installasjonskoden og gjenopprettingskodene. $tegn tilfeldige tegn gir 5 · $tegn bit.
     */
    public static function lesbarKode(int $tegn = 20): string
    {
        $alfabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ut = '';
        for ($i = 0; $i < $tegn; $i++) {
            $ut .= $alfabet[random_int(0, 31)];
        }

        return implode('-', str_split($ut, 4));
    }

    /** Normaliserer en lesbar kode før sammenligning: store bokstaver, uten mellomrom og bindestreker, O→0, I/L→1. */
    public static function normaliserKode(string $kode): string
    {
        $k = strtoupper((string) preg_replace('/[\s-]+/', '', $kode));

        return strtr($k, ['O' => '0', 'I' => '1', 'L' => '1']);
    }
}
