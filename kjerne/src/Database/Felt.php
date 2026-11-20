<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Database;

/**
 * Typekontroll av verdier fra databasen og fra dekodet JSON (blandede typer), uten tause gjetninger: en verdi av
 * feil type gir standardverdien, aldri en tilfeldig omgjøring av en matrise eller et objekt.
 */
final class Felt
{
    public static function tekst(mixed $v, string $standard = ''): string
    {
        return match (true) {
            is_string($v) => $v,
            is_int($v), is_float($v) => (string) $v,
            is_bool($v) => $v ? '1' : '0',
            default => $standard,
        };
    }

    public static function valgfriTekst(mixed $v): ?string
    {
        return $v === null ? null : self::tekst($v);
    }

    /**
     * Verdien, eller $standard når en PHP-funksjon ga false (scandir, glob, file, realpath …).
     *
     * @template T
     * @param T|false $v
     * @param T $standard
     * @return T
     */
    public static function ellers(mixed $v, mixed $standard): mixed
    {
        return $v === false ? $standard : $v;
    }

    public static function tall(mixed $v, int $standard = 0): int
    {
        return match (true) {
            is_int($v) => $v,
            is_string($v) && preg_match('/^-?\d+$/D', $v) === 1 => (int) $v,
            is_bool($v) => $v ? 1 : 0,
            default => $standard,
        };
    }
}
