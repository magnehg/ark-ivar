<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/** Filter for lesing og eksport av loggen. Datoene er UTC (ÅÅÅÅ-MM-DD), til-datoen er med. */
final class LoggFilter
{
    public function __construct(
        public readonly ?string $app = null,
        public readonly ?string $bruker = null,
        public readonly ?string $fra = null,
        public readonly ?string $til = null,
        public readonly ?string $type = null,
        public readonly ?string $korrelasjon = null,
        public readonly ?string $kategori = null,
    ) {
        foreach ([$fra, $til] as $d) {
            if ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) !== 1) {
                throw new \InvalidArgumentException('Datoen må være ÅÅÅÅ-MM-DD.');
            }
        }
        if ($kategori !== null && !in_array($kategori, Hendelse::KATEGORIER, true)) {
            throw new \InvalidArgumentException('Ukjent kategori.');
        }
    }
}
