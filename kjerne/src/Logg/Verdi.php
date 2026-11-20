<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * En verdi i før/etter med klartekst ved siden av koden: {kode, tekst} (BIV-LOG-03). For verdier som ikke er koder
 * (f.eks. et navn) er koden selve verdien.
 */
final class Verdi
{
    /** @return array{kode: scalar|null, tekst: string} */
    public static function kode(string|int|bool|null $kode, string $tekst): array
    {
        return ['kode' => $kode, 'tekst' => $tekst];
    }

    /** @return array{kode: scalar|null, tekst: string} */
    public static function tekst(?string $tekst): array
    {
        return ['kode' => $tekst, 'tekst' => $tekst ?? ''];
    }
}
