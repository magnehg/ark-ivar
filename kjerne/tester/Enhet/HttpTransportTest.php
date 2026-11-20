<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Http\HttpTransport;
use Bivrost\Kjerne\Preferanser\HttpSentralPreferanser;
use PHPUnit\Framework\TestCase;

/** Kjernens HttpTransport (BIV-B-024): en feil i transporten gjelder bare den ene forespørselen, som uten transport. */
final class HttpTransportTest extends TestCase
{
    public function testEnFeilStopperIkkeDeNesteForesporslene(): void
    {
        $t = new class implements HttpTransport {
            public int $kall = 0;

            public function send(string $metode, string $url, array $hoder, string $kropp): array
            {
                $this->kall++;
                if ($this->kall === 1) {
                    throw new \RuntimeException('tidsavbrudd');
                }
                if ($this->kall === 2) {
                    return ['status' => 99, 'hoder' => [], 'kropp' => '']; // ugyldig status: Response kaster
                }

                return $metode === 'GET'
                    ? ['status' => 200, 'hoder' => ['Content-Type' => 'application/scim+json'], 'kropp' => '{"schemas":["urn:ietf:params:scim:schemas:core:2.0:User"]}']
                    : ['status' => 204, 'hoder' => [], 'kropp' => ''];
            }
        };
        $p = new HttpSentralPreferanser('https://login.test', $t);
        self::assertNull($p->hent('t'), 'Transporten kaster: ingen preferanser.');
        self::assertNull($p->hent('t'), 'Ugyldig svar fra transporten: ingen preferanser.');
        self::assertNotNull($p->hent('t'), 'Neste forespørsel går gjennom igjen.');
        self::assertTrue($p->lagre('t', \Bivrost\Kjerne\Preferanser\Preferanser::standard()));
        self::assertSame(4, $t->kall, 'Transporten kalles for hver forespørsel.');
    }
}
