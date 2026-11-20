<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Eksport;

/**
 * Appens del av eksporten (BIV-EKS-01). Appen skriver sine data under nøkkelen «app» i eksporten, med klartekst ved
 * siden av koder (BIV-LOG-03). Bivrost kjenner ikke formatet.
 */
interface EksportBidrag
{
    /**
     * Navnet og versjonen på appens format, f.eks. ['gerd-eksport', 1].
     *
     * @return array{0: string, 1: int}
     */
    public function format(): array;

    public function skriv(JsonStrom $s): void;
}
