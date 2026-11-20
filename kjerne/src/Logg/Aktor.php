<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * Hvem som gjør noe, som øyeblikksbilde (BIV-LOG-02): navn og tittel slik de var da hendelsen skjedde, i klartekst
 * ved siden av nøkkelen. Loggen endres ikke når brukeren senere får nytt navn eller deaktiveres.
 *
 * Typer: bruker (logget inn via Bivrost Sentral; id = «sub» fra Sentral), lokal (nødinnlogging, lokal
 * driftsbruker), api (en maskin med en nøkkel; navnet er nøkkelens navn), kommandolinje, system.
 * NB: navn og tittel er personopplysninger. Formålet er revisjonsspor; personvernerklæringen må si det.
 */
final class Aktor
{
    public const TYPER = ['bruker', 'lokal', 'api', 'kommandolinje', 'system'];

    public function __construct(
        public readonly string $type,
        public readonly ?string $id,
        public readonly string $navn,
        public readonly ?string $tittel = null,
    ) {
        if (!in_array($type, self::TYPER, true)) {
            throw new \InvalidArgumentException('Ukjent aktørtype.');
        }
        if (trim($navn) === '') {
            throw new \InvalidArgumentException('En aktør må ha et navn.');
        }
    }

    public static function bruker(string $sub, string $navn, ?string $tittel = null): self
    {
        return new self('bruker', $sub, $navn, $tittel);
    }

    public static function lokal(string $id, string $navn, ?string $tittel = null): self
    {
        return new self('lokal', $id, $navn, $tittel);
    }

    /** En maskin med en nøkkel, f.eks. maskin('Oppdateringsnøkkel «GitHub Actions»'). */
    public static function maskin(string $navn, ?string $id = null): self
    {
        return new self('api', $id, $navn);
    }

    /** Systemet selv, med en tydelig tekst, f.eks. system('System (installasjon)'). */
    public static function system(string $navn = 'System'): self
    {
        return new self('system', null, $navn);
    }

    public static function kommandolinje(): self
    {
        $os = null;
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            $os = is_array($pw) ? $pw['name'] : null;
        }

        return new self('kommandolinje', null, 'Kommandolinje' . ($os !== null && $os !== '' ? " ($os)" : ''));
    }
}
