<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

/**
 * Det Kjerne sender over nettet til Sentral (OIDC og preferanser), som Kjernens egen type (BIV-B-024). Kjerne bruker
 * Guzzle innvendig, men Guzzle er prefikset i versjonspakken (Bivrost\Vendor\…), så verken appene eller testene skal
 * se Guzzle-typer. Uten transport går forespørslene rett ut på nettet; med en transport (f.eks. en falsk Sentral i
 * testene) går de hit i stedet.
 */
interface HttpTransport
{
    /**
     * @param array<string, list<string>> $hoder
     * @return array{status: int, hoder: array<string, list<string>|string>, kropp: string}
     */
    public function send(string $metode, string $url, array $hoder, string $kropp): array;
}
