<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Feil;

/**
 * En feil som skal bli et HTTP-svar med en bestemt statuskode og en maskinlesbar kode (f.eks. «oppdatering_pagar»).
 * Svaret får teksten på brukerens språk, aldri stakkspor eller interne detaljer.
 */
final class HttpFeil extends BivrostFeil
{
    /**
     * @param array<string, scalar|null> $verdier
     * @param array<string, string> $hoder Ekstra svarhoder, f.eks. Retry-After.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $kode,
        string $tekstnokkel,
        array $verdier = [],
        public readonly array $hoder = [],
        ?\Throwable $forrige = null,
    ) {
        parent::__construct($tekstnokkel, $verdier, $forrige);
    }
}
