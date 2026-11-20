<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * Skriver i loggen og sender i tillegg hendelsen som syslog (RFC 5424) til en mottaker, f.eks. et SIEM
 * (BIV-LOG-07). Oppsett i .env: BIVROST_SYSLOG=udp://vert:514 eller tcp://vert:514 (tls:// for TCP med TLS,
 * RFC 5425) og BIVROST_SYSLOG_PEN=<virksomhetens IANA-nummer> for strukturerte data (Syslog, BIV-B-026).
 * Videresendingen stopper aldri det som logges: feil skrives i serverens feillogg.
 */
final class VideresendendeLoggLager implements LoggLager
{
    /** @param (callable(string): void)|null $send Byttes ut i tester. */
    public function __construct(
        private readonly LoggLager $lager,
        private readonly string $mottaker,
        private readonly string $app,
        private $send = null,
        private readonly ?int $pen = null,
    ) {
        if (preg_match('#^(udp|tcp|tls)://[A-Za-z0-9.-]+:\d{1,5}$#D', $mottaker) !== 1) {
            throw new \InvalidArgumentException('BIVROST_SYSLOG må være udp://, tcp:// eller tls://vert:port.');
        }
    }

    public function skriv(Hendelse $h): void
    {
        $this->lager->skriv($h);
        $melding = Syslog::melding($h, $this->app, (string) gethostname(), $this->pen);
        try {
            if ($this->send !== null) {
                ($this->send)($melding);

                return;
            }
            $s = @stream_socket_client($this->mottaker, $feilnr, $feil, 2);
            if ($s === false) {
                error_log('Bivrost: fikk ikke kontakt med syslog-mottakeren.');

                return;
            }
            // TCP og TLS: lengdeprefiks etter RFC 6587 (octet counting). UDP: én melding per datagram.
            fwrite($s, str_starts_with($this->mottaker, 'udp://') ? $melding : strlen($melding) . ' ' . $melding);
            fclose($s);
        } catch (\Throwable) {
            error_log('Bivrost: fikk ikke videresendt en hendelse til syslog.');
        }
    }

    public function les(LoggFilter $filter, int $antall = 200, ?int $forNr = null): array
    {
        return $this->lager->les($filter, $antall, $forNr);
    }

    public function hverEn(LoggFilter $filter, callable $hver): int
    {
        return $this->lager->hverEn($filter, $hver);
    }

    public function ryddSikkerhetslogg(int $dager): int
    {
        return $this->lager->ryddSikkerhetslogg($dager);
    }
}
