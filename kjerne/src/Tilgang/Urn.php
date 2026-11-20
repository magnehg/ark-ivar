<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tilgang;

/**
 * URN-er for roller og tilganger (RFC 8141-form, BIV-ROL-02). URN-en er den faste nøkkelen; navnet til visning kan
 * endres uten at tildelinger eller logg endres.
 *
 *   urn:bivrost:tilgang:<navn>                 tilgang på tvers av appene (f.eks. drift, brukeradministrator)
 *   urn:bivrost:app:<app>:tilgang:<navn>       tilgang i én app (appadministrator, brukeradministrator)
 *   urn:bivrost:app:<app>:rolle:<navn>         appens fagrolle (fra rolleregisteret)
 */
final class Urn
{
    /** Navnet på en rolle eller tilgang: små bokstaver a–z, tall og bindestrek. */
    public const NAVN = '/^[a-z][a-z0-9-]{0,62}$/D';
    public const APP = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const DRIFT = 'urn:bivrost:tilgang:drift';
    public const BRUKERADMINISTRATOR = 'urn:bivrost:tilgang:brukeradministrator';

    /** Tilganger på tvers av appene som Bivrost selv definerer. */
    public const FELLES_TILGANGER = ['drift', 'brukeradministrator'];

    /** Tilganger per app som Bivrost selv definerer. */
    public const APP_TILGANGER = ['appadministrator', 'brukeradministrator'];

    public static function tilgang(string $navn): string
    {
        self::sjekk(self::NAVN, $navn);

        return 'urn:bivrost:tilgang:' . $navn;
    }

    public static function appTilgang(string $app, string $navn): string
    {
        self::sjekk(self::APP, $app);
        self::sjekk(self::NAVN, $navn);

        return "urn:bivrost:app:$app:tilgang:$navn";
    }

    public static function rolle(string $app, string $navn): string
    {
        self::sjekk(self::APP, $app);
        self::sjekk(self::NAVN, $navn);

        return "urn:bivrost:app:$app:rolle:$navn";
    }

    /**
     * Deler opp en URN. Gir null for alt som ikke er en gyldig Bivrost-URN for en rolle eller tilgang.
     *
     * @return array{slag: 'rolle'|'tilgang', app: ?string, navn: string}|null
     */
    public static function tolk(string $urn): ?array
    {
        if (preg_match('/^urn:bivrost:tilgang:([a-z][a-z0-9-]{0,62})$/D', $urn, $m) === 1) {
            return ['slag' => 'tilgang', 'app' => null, 'navn' => $m[1]];
        }
        if (preg_match('/^urn:bivrost:app:([a-z][a-z0-9-]{0,39}):(rolle|tilgang):([a-z][a-z0-9-]{0,62})$/D', $urn, $m) === 1) {
            return ['slag' => $m[2] === 'rolle' ? 'rolle' : 'tilgang', 'app' => $m[1], 'navn' => $m[3]];
        }

        return null;
    }

    /** Om URN-en gjelder appen (eller er felles for alle apper). */
    public static function gjelderApp(string $urn, string $app): bool
    {
        $d = self::tolk($urn);

        return $d !== null && ($d['app'] === null || $d['app'] === $app);
    }

    private static function sjekk(string $monster, string $verdi): void
    {
        if (preg_match($monster, $verdi) !== 1) {
            throw new \InvalidArgumentException('Ugyldig navn i URN.');
        }
    }
}
