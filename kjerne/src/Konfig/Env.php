<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Konfig;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Innstillinger og hemmeligheter fra en .env-fil utenfor webroten (SIK-04, BIV-SIK-03). Filen leses av Bivrost selv;
 * ingenting legges i miljøvariabler (putenv) eller $_ENV, så verdiene ikke lekker til underprosesser eller feilsider.
 *
 * Format: én NØKKEL=verdi per linje, nøkkelen med store bokstaver, tall og _. Verdien kan stå i "…" med \" \\ og \n.
 * Linjer som begynner med # er kommentarer. En ugyldig linje gir feil (i stedet for å bli oversett i stillhet).
 */
final class Env
{
    /** @param array<string, string> $verdier */
    private function __construct(private readonly array $verdier)
    {
    }

    /** @param array<string, string> $verdier */
    public static function fraListe(array $verdier): self
    {
        foreach ($verdier as $k => $v) {
            self::sjekkNokkel($k);
            if (self::verdiFeil($v) !== null) {
                throw new BivrostFeil('bivrost.feil.env.verdi', ['nokkel' => $k]);
            }
        }

        return new self($verdier);
    }

    public static function fraFil(string $fil): self
    {
        if (!is_file($fil)) {
            return new self([]);
        }
        $innhold = @file_get_contents($fil);
        if ($innhold === false) {
            throw new BivrostFeil('bivrost.feil.env.les');
        }

        return new self(self::tolk($innhold));
    }

    /** @return array<string, string> */
    public static function tolk(string $innhold): array
    {
        if (str_starts_with($innhold, "\xEF\xBB\xBF")) {
            $innhold = substr($innhold, 3);
        }
        $ut = [];
        foreach (Felt::ellers(preg_split('/\r\n|\n|\r/', $innhold), []) as $nr => $linje) {
            $l = trim($linje);
            if ($l === '' || str_starts_with($l, '#')) {
                continue;
            }
            if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D', $l, $m) !== 1) {
                throw new BivrostFeil('bivrost.feil.env.linje', ['linje' => $nr + 1]);
            }
            $v = $m[2];
            if (str_starts_with($v, '"')) {
                if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(#.*)?$/D', $v, $q) !== 1) {
                    throw new BivrostFeil('bivrost.feil.env.linje', ['linje' => $nr + 1]);
                }
                $v = (string) preg_replace_callback('/\\\\(.)/', static fn (array $x): string => match ($x[1]) {
                    'n' => "\n",
                    default => $x[1],
                }, $q[1]);
            } else {
                $v = rtrim((string) preg_replace('/\s+#.*$/D', '', $v));
            }
            $ut[$m[1]] = $v;
        }

        return $ut;
    }

    public function hent(string $nokkel, ?string $standard = null): ?string
    {
        return $this->verdier[$nokkel] ?? $standard;
    }

    public function krev(string $nokkel): string
    {
        $v = $this->verdier[$nokkel] ?? '';
        if ($v === '') {
            throw new BivrostFeil('bivrost.feil.env.mangler', ['nokkel' => $nokkel]);
        }

        return $v;
    }

    public function sann(string $nokkel, bool $standard = false): bool
    {
        $v = $this->verdier[$nokkel] ?? null;
        if ($v === null || $v === '') {
            return $standard;
        }

        return in_array(strtolower($v), ['1', 'ja', 'true', 'on'], true);
    }

    public function heltall(string $nokkel, int $standard): int
    {
        $v = $this->verdier[$nokkel] ?? null;

        return $v !== null && preg_match('/^-?\d+$/D', $v) === 1 ? (int) $v : $standard;
    }

    /** @return array<string, string> */
    public function alle(): array
    {
        return $this->verdier;
    }

    /**
     * En ny Env med flere verdier (overstyrer).
     *
     * @param array<string, string> $verdier
     */
    public function med(array $verdier): self
    {
        return self::fraListe(array_merge($this->verdier, $verdier));
    }

    /** Grunnen til at en verdi ikke kan skrives til .env, eller null. */
    public static function verdiFeil(string $v): ?string
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $v) === 1) {
            return 'kontrolltegn';
        }
        if (strlen($v) > 4096) {
            return 'for_lang';
        }

        return null;
    }

    private static function sjekkNokkel(string $k): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $k) !== 1) {
            throw new BivrostFeil('bivrost.feil.env.nokkel');
        }
    }

    /**
     * Teksten til en .env-fil med verdiene (alle i anførselstegn).
     *
     * @param array<string, string> $verdier
     */
    public static function lagInnhold(array $verdier, string $kommentar = ''): string
    {
        $ut = $kommentar !== '' ? '# ' . str_replace("\n", "\n# ", $kommentar) . "\n" : '';
        foreach ($verdier as $k => $v) {
            self::sjekkNokkel($k);
            if (self::verdiFeil($v) !== null) {
                throw new BivrostFeil('bivrost.feil.env.verdi', ['nokkel' => $k]);
            }
            $ut .= $k . '="' . addcslashes($v, '"\\') . "\"\n";
        }

        return $ut;
    }

    /**
     * Skriver .env atomisk (midlertidig fil og navnebytte) med rettighetene 0640. Finnes filen, skrives den bare når
     * $overskriv er satt.
     *
     * @param array<string, string> $verdier
     */
    public static function skrivFil(string $fil, array $verdier, string $kommentar = '', bool $overskriv = false): void
    {
        if (is_file($fil) && !$overskriv) {
            throw new BivrostFeil('bivrost.feil.env.finnes');
        }
        $innhold = self::lagInnhold($verdier, $kommentar);
        $tmp = $fil . '.ny-' . bin2hex(random_bytes(4));
        $gammel = umask(0027);
        try {
            $ok = @file_put_contents($tmp, $innhold, LOCK_EX) === strlen($innhold);
        } finally {
            umask($gammel);
        }
        if (!$ok || !@chmod($tmp, 0640) || !@rename($tmp, $fil)) {
            @unlink($tmp);
            throw new BivrostFeil('bivrost.feil.env.skriv');
        }
    }
}
