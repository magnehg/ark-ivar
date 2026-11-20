<?php

declare(strict_types=1);

namespace ArkIvar\Brukere;

use Bivrost\Kjerne\Tilgang\Urn;

/**
 * Rollene i Ark-Ivar. De er et hierarki: en lærer kan alt en student kan, og en administrator kan alt en lærer kan.
 * En bruker har én rolle i databasen og får URN-ene for den og rollene under, så tilgangssjekken i Kjerne
 * (Rbac::krev) kan spørre etter én rolle.
 */
final class Roller
{
    public const APP = 'arkivar';
    public const STUDENT = 'student';
    public const LAERER = 'laerer';
    public const ADMINISTRATOR = 'administrator';

    /** Rollene i stigende rekkefølge. */
    public const ALLE = [self::STUDENT, self::LAERER, self::ADMINISTRATOR];

    public static function gyldig(string $rolle): bool
    {
        return in_array($rolle, self::ALLE, true);
    }

    public static function urn(string $rolle): string
    {
        return Urn::rolle(self::APP, $rolle);
    }

    /**
     * URN-ene en bruker med rollen har: rollen selv og rollene under den.
     *
     * @return list<string>
     */
    public static function urner(string $rolle): array
    {
        $nivaa = array_search($rolle, self::ALLE, true);
        if ($nivaa === false) {
            return [];
        }

        return array_map(self::urn(...), array_slice(self::ALLE, 0, $nivaa + 1));
    }
}
