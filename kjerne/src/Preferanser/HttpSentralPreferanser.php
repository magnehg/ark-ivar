<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Preferanser;

use Bivrost\Kjerne\Http\Guzzle;
use Bivrost\Kjerne\Http\HttpTransport;
use Bivrost\Vendor\GuzzleHttp\ClientInterface;

/**
 * Preferansene hos Sentral over SCIM 2.0 (RFC 7644 § 3.11 /Me og § 3.5.2 PATCH). Feil (nettverk, 5xx, 401) gir
 * null/false, aldri et unntak: preferansene skal aldri stoppe en side.
 */
final class HttpSentralPreferanser implements SentralPreferanser
{
    private readonly ClientInterface $http;

    /**
     * @param string $sentral Adressen til Sentral (BIVROST_SENTRAL_URL), uten /scim/v2.
     * @param HttpTransport|null $transport Bare for tester. Tidsavbruddene er korte (5 s, tilkobling 3 s), fordi
     *     kallet skjer mens brukeren venter: når en preferanse endres (PUT /bivrost/api/meg/preferanser) og ved
     *     innloggingen (Preferansetjeneste::fraInnlogging). Svarer ikke Sentral, venter endringen lokalt (BIV-PRF-04).
     *     Kjerne kaller bare lagre() ennå; hent() brukes ikke (preferansene kommer fra claimet ved innloggingen).
     */
    public function __construct(private readonly string $sentral, ?HttpTransport $transport = null)
    {
        $this->http = Guzzle::klient(5, 3, $transport);
    }

    /**
     * GET /scim/v2/Me med brukerens eget tilgangstoken (RFC 7644 § 3.11, BIV-PRF-01, BIV-PRF-03): bare brukerens egne
     * preferanser, uten en egen maskinnøkkel i Kjerne. Ukjente felt og ugyldige verdier fra Sentral overses
     * (Preferanser::fraScim), så en nyere Sentral ikke stopper en eldre app.
     */
    public function hent(string $tilgangstoken): ?Preferanser
    {
        try {
            $s = $this->http->request('GET', rtrim($this->sentral, '/') . '/scim/v2/Me', [
                'headers' => ['Authorization' => 'Bearer ' . $tilgangstoken, 'Accept' => 'application/scim+json'],
            ]);
            if ($s->getStatusCode() !== 200) {
                return null;
            }
            $j = json_decode((string) $s->getBody(), true);
            if (!is_array($j)) {
                return null;
            }
            $d = [];
            foreach ($j as $k => $v) {
                $d[(string) $k] = $v;
            }

            return Preferanser::fraScim($d);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * PATCH /scim/v2/Me med PatchOp «replace» for hvert felt som er satt (RFC 7644 § 3.5.2, BIV-PRF-03). Gir false ved
     * alle feil, og endringen venter da lokalt (bivrost_preferanse.synkronisert = 0) til neste innlogging (BIV-PRF-04).
     */
    public function lagre(string $tilgangstoken, Preferanser $felles): bool
    {
        $ops = [];
        foreach ($felles->scim() as $sti => $verdi) {
            $ops[] = ['op' => 'replace', 'path' => $sti, 'value' => $verdi];
        }
        try {
            $s = $this->http->request('PATCH', rtrim($this->sentral, '/') . '/scim/v2/Me', [
                'headers' => ['Authorization' => 'Bearer ' . $tilgangstoken, 'Content-Type' => 'application/scim+json', 'Accept' => 'application/scim+json'],
                'body' => json_encode([
                    'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
                    'Operations' => $ops,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);

            return $s->getStatusCode() >= 200 && $s->getStatusCode() < 300;
        } catch (\Throwable) {
            return false;
        }
    }
}
