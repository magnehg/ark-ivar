<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Preferanser;

/**
 * Brukerens preferanser for utseende og språk (BIV-PRF-01–06), felles for alle appene som bruker Bivrost. Bivrost
 * Sentral eier dem på brukerkontoen (SCIM), Kjerne bruker dem og husker siste verdier lokalt.
 *
 * Feltene og SCIM-tilordningen:
 *   preferredLanguage  SCIM-kjernen «preferredLanguage» (BCP 47: nb, nn, en)
 *   locale             SCIM-kjernen «locale» (f.eks. nb-NO) og OIDC-claimet «locale»
 *   timezone           SCIM-kjernen «timezone» (IANA, f.eks. Europe/Oslo) og OIDC-claimet «zoneinfo»
 *   tema, modus, bredde, tetthet, redusertBevegelse, hoyKontrast
 *                      utvidelsen urn:bivrost:scim:schemas:extension:2.0:Preferanser
 *
 * En verdi som ikke er satt (null), arves fra nivået under: app > felles > system (standardverdiene her).
 */
final class Preferanser
{
    public const SCIM_UTVIDELSE = 'urn:bivrost:scim:schemas:extension:2.0:Preferanser';

    /** Lovlige verdier for feltene med kodelister. Det første er standard. */
    public const VERDIER = [
        'tema' => ['marine', 'teal', 'mose', 'arkivbrun', 'vinrod'],
        'modus' => ['system', 'lys', 'mork'],
        'bredde' => ['begrenset', 'full'],
        'tetthet' => ['romslig', 'kompakt'],
        'redusertBevegelse' => ['system', 'ja', 'nei'],
        'hoyKontrast' => ['system', 'ja', 'nei'],
        'preferredLanguage' => ['nb', 'nn', 'en'],
    ];

    /** Standardverdiene for de frie feltene. */
    public const STANDARD_FRIE = ['locale' => 'nb-NO', 'timezone' => 'Europe/Oslo'];

    public const FELT = ['tema', 'modus', 'bredde', 'tetthet', 'redusertBevegelse', 'hoyKontrast', 'preferredLanguage', 'locale', 'timezone'];

    /** @param array<string, string> $verdier Bare satte felt. */
    private function __construct(private readonly array $verdier)
    {
    }

    /** Ingen felt satt: alt arves fra nivået under (BIV-PRF-05). Brukes når en bruker ikke har lagrede valg. */
    public static function tom(): self
    {
        return new self([]);
    }

    /** Standardverdiene (system-nivået). */
    public static function standard(): self
    {
        $v = self::STANDARD_FRIE;
        foreach (self::VERDIER as $k => $l) {
            $v[$k] = $l[0];
        }

        return new self($v);
    }

    /**
     * Fra en liste (API, claim, database). Ukjente felt og ugyldige verdier gir InvalidArgumentException med feltet,
     * når $streng; ellers overses de (data fra Sentral eller en eldre versjon skal aldri stoppe innloggingen).
     *
     * @param array<array-key, mixed> $d
     */
    public static function fraListe(array $d, bool $streng = true): self
    {
        $ut = [];
        foreach ($d as $k => $v) {
            $k = (string) $k;
            if (!in_array($k, self::FELT, true)) {
                if ($streng) {
                    throw new \InvalidArgumentException($k);
                }
                continue;
            }
            if ($v === null) {
                continue;
            }
            if (!is_string($v) || !self::gyldig($k, $v)) {
                if ($streng) {
                    throw new \InvalidArgumentException($k);
                }
                continue;
            }
            $ut[$k] = $v;
        }

        return new self($ut);
    }

    /**
     * Om verdien er lovlig for feltet: kodelistene i VERDIER, de samme som enum i skjema/preferanser.schema.json for
     * tema, modus, bredde, tetthet, redusertBevegelse og hoyKontrast. preferredLanguage er her begrenset til nb, nn og
     * en, mens skjemaet godtar alle BCP 47-koder. locale er språk med eventuelt land (nb eller nb-NO, samme mønster som
     * skjemaet), og timezone et IANA-navn PHP kjenner. Kontrollen gjøres på serveren, fordi verdiene blir
     * data-attributter i grensesnittet (BIV-PRF-01, BIV-SIK-05).
     */
    public static function gyldig(string $felt, string $verdi): bool
    {
        if (isset(self::VERDIER[$felt])) {
            return in_array($verdi, self::VERDIER[$felt], true);
        }

        return match ($felt) {
            'locale' => preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/D', $verdi) === 1,
            'timezone' => in_array($verdi, \DateTimeZone::listIdentifiers(), true),
            default => false,
        };
    }

    /** Verdien på dette nivået, eller null når feltet ikke er satt her (og arves fra nivået under, BIV-PRF-05). */
    public function hent(string $felt): ?string
    {
        return $this->verdier[$felt] ?? null;
    }

    /** @return array<string, string> */
    public function tilListe(): array
    {
        return $this->verdier;
    }

    /** Disse verdiene over de i $under (som fyller ut det som mangler). */
    public function over(self $under): self
    {
        return new self(array_merge($under->verdier, $this->verdier));
    }

    /**
     * Med endringene; null i $endringer fjerner feltet (arves da fra nivået under).
     *
     * @param array<string, string|null> $endringer
     */
    public function med(array $endringer): self
    {
        $v = $this->verdier;
        foreach ($endringer as $k => $x) {
            if (!in_array($k, self::FELT, true) || ($x !== null && !self::gyldig($k, $x))) {
                throw new \InvalidArgumentException($k);
            }
            if ($x === null) {
                unset($v[$k]);
            } else {
                $v[$k] = $x;
            }
        }

        return new self($v);
    }

    /**
     * Som SCIM 2.0-attributter (RFC 7643): kjernefeltene øverst og resten i Bivrost-utvidelsen.
     *
     * @return array<string, mixed>
     */
    public function scim(): array
    {
        $ut = [];
        $utv = [];
        foreach ($this->verdier as $k => $v) {
            if (in_array($k, ['preferredLanguage', 'locale', 'timezone'], true)) {
                $ut[$k] = $v;
            } else {
                $utv[$k] = $v;
            }
        }
        if ($utv !== []) {
            $ut[self::SCIM_UTVIDELSE] = $utv;
        }

        return $ut;
    }

    /** @param array<string, mixed> $scim */
    public static function fraScim(array $scim): self
    {
        $d = [];
        foreach (['preferredLanguage', 'locale', 'timezone'] as $k) {
            if (isset($scim[$k])) {
                $d[$k] = $scim[$k];
            }
        }
        $utv = $scim[self::SCIM_UTVIDELSE] ?? [];
        foreach (is_array($utv) ? $utv : [] as $k => $v) {
            $d[(string) $k] = $v;
        }

        return self::fraListe($d, false);
    }
}
