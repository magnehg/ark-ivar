<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Innlogging;

/**
 * Hashing av passord med PHPs password_hash (AUT-03, ASVS V2.4): Argon2id når PHP har det (anbefalt), ellers bcrypt.
 * bcrypt bruker bare de første 72 bytene, så før bcrypt hashes passordet med SHA-384 og kodes som base64 (64 tegn,
 * ingen nullbyte) – kjent mønster som gjør at hele passordet teller. needsRehash() oppgraderer ved neste innlogging
 * når algoritmen eller kostnaden endres.
 */
final class Passordhash
{
    private const BCRYPT_FORHASH = 'bivrost-bcrypt-sha384$';

    private static ?string $attrapp = null;

    public static function hash(string $passord): string
    {
        $p = Passordregler::normaliser($passord);
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($p, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }

        return self::BCRYPT_FORHASH . password_hash(self::forhash($p), PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function stemmer(string $passord, string $hash): bool
    {
        $p = Passordregler::normaliser($passord);
        if (str_starts_with($hash, self::BCRYPT_FORHASH)) {
            return password_verify(self::forhash($p), substr($hash, strlen(self::BCRYPT_FORHASH)));
        }

        return password_verify($p, $hash);
    }

    public static function trengerNy(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return str_starts_with($hash, self::BCRYPT_FORHASH)
                || password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }

        return !str_starts_with($hash, self::BCRYPT_FORHASH)
            || password_needs_rehash(substr($hash, strlen(self::BCRYPT_FORHASH)), PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** En hash som aldri stemmer, til å bruke like lang tid når brukeren ikke finnes. */
    public static function attrapp(): string
    {
        return self::$attrapp ??= self::hash(bin2hex(random_bytes(16)));
    }

    private static function forhash(string $p): string
    {
        return base64_encode(hash('sha384', $p, true));
    }
}
