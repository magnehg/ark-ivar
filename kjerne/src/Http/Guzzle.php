<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

use Bivrost\Vendor\GuzzleHttp\Client;
use Bivrost\Vendor\GuzzleHttp\ClientInterface;
use Bivrost\Vendor\GuzzleHttp\Handler\MockHandler;
use Bivrost\Vendor\GuzzleHttp\HandlerStack;
use Bivrost\Vendor\GuzzleHttp\Psr7\Response;
use Bivrost\Vendor\Psr\Http\Message\RequestInterface;
use Bivrost\Vendor\Psr\Http\Message\ResponseInterface;

/**
 * Lager Guzzle-klienten Kjerne bruker innvendig (league/oauth2-client trenger den): ingen omdirigeringer, ingen
 * unntak for 4xx/5xx og faste tidsgrenser. Med en HttpTransport går forespørslene gjennom den i stedet for ut på
 * nettet (Guzzles MockHandler, som fylles på etter hvert svar). Bare for Kjerne selv (BIV-B-024): Guzzle-typene er
 * prefikset i versjonspakken og er ikke en del av grensesnittet mot appene.
 *
 * @internal
 */
final class Guzzle
{
    public static function klient(float $tidsgrense, float $tilkobling, ?HttpTransport $transport = null): ClientInterface
    {
        $valg = ['timeout' => $tidsgrense, 'connect_timeout' => $tilkobling, 'allow_redirects' => false, 'http_errors' => false];
        if ($transport === null) {
            return new Client($valg);
        }
        // En feil i transporten (eller et ugyldig svar) blir en avvist forespørsel, som en nettverksfeil uten transport.
        // MockHandler gjør da om unntaket til et avvist løfte og kaller onRejected, så køen fylles på igjen.
        $svar = static function (RequestInterface $r) use ($transport): ResponseInterface|\Throwable {
            try {
                /** @var array<string, list<string>> $hoder */
                $hoder = $r->getHeaders();
                $s = $transport->send($r->getMethod(), (string) $r->getUri(), $hoder, (string) $r->getBody());

                return new Response($s['status'], $s['hoder'], $s['kropp']);
            } catch (\Throwable $e) {
                return $e;
            }
        };
        // Køen har alltid ett svar: hvert svar og hver feil legger et nytt inn.
        $ko = new class ($svar) {
            public ?MockHandler $mock = null;

            public function __construct(private readonly \Closure $svar)
            {
            }

            public function fyllPa(): void
            {
                $this->mock?->append($this->svar);
            }
        };
        $mock = new MockHandler([$svar], $ko->fyllPa(...), $ko->fyllPa(...));
        $ko->mock = $mock;

        return new Client(['handler' => HandlerStack::create($mock)] + $valg);
    }
}
