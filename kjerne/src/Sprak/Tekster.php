<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sprak;

use Bivrost\Kjerne\Database\Felt;

/**
 * Språkfilene (BIV-SPR-01–07), samme oppsett som Gerð: én JSON-fil per modul og språk med ICU MessageFormat,
 * i18n/<språk>/<modul>.json, og nøkler med prefiks per eier («bivrost.» for Bivrost, «gerd.» for Gerð …).
 * Grensesnittet (react-intl) og serveren bruker de samme filene og nøklene.
 *
 * Mappene leses i rekkefølge (Bivrost Kjerne først, så appen, så språkpakker); en senere mappe kan legge til nøkler og
 * språk. Bokmål, nynorsk og engelsk følger med. Et nytt språk er en språkpakke: en mappe i18n/<kode>/ med JSON-filene
 * og manifestet sprakpakke.json (BCP 47-kode, navn og versjon, se docs/sprakpakker.md) – uten kodeendring.
 *
 * Mangler en tekst på et språk, brukes hovedspråket (SPR-05; kalleren kan merke den med lang via kilde()), og mangler
 * den der også, gis nøkkelen selv. Formatering med MessageFormatter (intl) når den finnes, ellers IcuFormatter.
 */
final class Tekster
{
    public const INNEBYGDE = ['nb', 'nn', 'en'];

    /** @var array<string, array<string, string>> */
    private array $kataloger = [];

    /** @var array<string, array{kode: string, navn: array<string, string>, versjon: string}>|null */
    private ?array $pakker = null;

    /** @param list<string> $mapper i18n-mappene (med <språk>/*.json), de senere legger til og overstyrer. */
    public function __construct(
        private readonly array $mapper = [__DIR__ . '/../../i18n'],
        private readonly string $hovedsprak = 'nb',
        private readonly bool $brukIntl = true,
    ) {
    }

    public function hovedsprak(): string
    {
        return $this->hovedsprak;
    }

    /**
     * Språkpakkene som finnes i mappene (manifestet sprakpakke.json), med kode som nøkkel.
     *
     * @return array<string, array{kode: string, navn: array<string, string>, versjon: string}>
     */
    public function pakker(): array
    {
        if ($this->pakker !== null) {
            return $this->pakker;
        }
        $ut = [];
        foreach ($this->mapper as $m) {
            foreach (Felt::ellers(glob($m . '/*/sprakpakke.json'), []) as $fil) {
                $p = Sprakpakke::les($fil);
                if ($p !== null && basename(dirname($fil)) === $p['kode']) {
                    $ut[$p['kode']] = $p;
                }
            }
        }

        return $this->pakker = $ut;
    }

    /** @return list<string> Språkene som kan velges: de innebygde og språkpakkene. */
    public function sprak(): array
    {
        return array_values(array_unique(array_merge(self::INNEBYGDE, array_keys($this->pakker()))));
    }

    /** @return array<string, string> */
    public function katalog(string $sprak): array
    {
        if (!in_array($sprak, $this->sprak(), true)) {
            $sprak = $this->hovedsprak;
        }
        if (!isset($this->kataloger[$sprak])) {
            $alle = [];
            foreach ($this->mapper as $m) {
                $filer = Felt::ellers(glob("$m/$sprak/*.json"), []);
                sort($filer);
                foreach ($filer as $fil) {
                    if (basename($fil) === 'sprakpakke.json') {
                        continue;
                    }
                    $j = json_decode((string) file_get_contents($fil), true, 16, JSON_THROW_ON_ERROR);
                    foreach (is_array($j) ? $j : [] as $k => $v) {
                        if (is_string($k) && is_string($v)) {
                            $alle[$k] = $v;
                        }
                    }
                }
            }
            $this->kataloger[$sprak] = $alle;
        }

        return $this->kataloger[$sprak];
    }

    /** Språket teksten faktisk står på (til lang-attributtet), eller null når nøkkelen ikke finnes. */
    public function kilde(string $nokkel, string $sprak): ?string
    {
        if (isset($this->katalog($sprak)[$nokkel])) {
            return $sprak;
        }

        return isset($this->katalog($this->hovedsprak)[$nokkel]) ? $this->hovedsprak : (isset($this->katalog('nb')[$nokkel]) ? 'nb' : null);
    }

    /** @param array<string, scalar|null> $verdier */
    public function tekst(string $nokkel, array $verdier = [], ?string $sprak = null): string
    {
        $sprak ??= $this->hovedsprak;
        $kilde = $this->kilde($nokkel, $sprak);
        if ($kilde === null) {
            return $nokkel;
        }

        return $this->formater($this->katalog($kilde)[$nokkel], $verdier, $kilde);
    }

    public function finnes(string $nokkel): bool
    {
        return $this->kilde($nokkel, $this->hovedsprak) !== null;
    }

    /** @param array<string, scalar|null> $verdier */
    public function formater(string $monster, array $verdier, string $sprak): string
    {
        if ($this->brukIntl && class_exists(\MessageFormatter::class)) {
            try {
                $ut = (new \MessageFormatter(self::lokale($sprak), $monster))->format(array_map(static fn ($v) => $v ?? '', $verdier));
                if (is_string($ut)) {
                    return $ut;
                }
            } catch (\IntlException | \ValueError) {
                // Ugyldig mønster: erstatningen under gir det som går.
            }
        }

        return IcuFormatter::formater($monster, $verdier, $sprak);
    }

    /** ICU-lokalet for et språk (BCP 47 → ICU): nb → nb_NO, nn → nn_NO, en → en_GB, ellers koden selv. */
    public static function lokale(string $sprak): string
    {
        return match ($sprak) {
            'nb' => 'nb_NO',
            'nn' => 'nn_NO',
            'en' => 'en_GB',
            default => str_replace('-', '_', $sprak),
        };
    }
}
