<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Innlogging;

use Bivrost\Vendor\OTPHP\TOTP as OtpTotp;
use Psr\Clock\ClockInterface;

/**
 * Tofaktor med engangskoder (TOTP, RFC 6238) via biblioteket spomky-labs/otphp (BIV-AUT-05, AUT-04, ASVS V2.8):
 * SHA-1, 6 sifre og 30 sekunder (det alle autentiseringsappene støtter), hemmeligheten 160 bit tilfeldig.
 * Koden godtas i nåværende tidssteg og ett steg før eller etter (klokkeavvik). Hvert tidssteg kan bare brukes én gang:
 * kalleren lagrer det siste brukte steget og gir det inn igjen (vern mot gjenbruk, ASVS 2.8.4).
 */
final class Totp
{
    public const PERIODE = 30;
    public const SIFRE = 6;

    public function __construct(private readonly ClockInterface $klokke)
    {
    }

    /** En ny hemmelighet (base32). */
    public function nyHemmelighet(): string
    {
        return OtpTotp::generate($this->klokke)->getSecret();
    }

    /** Adressen til QR-koden (otpauth://totp/…), med utsteder og konto i klartekst. */
    public function adresse(string $hemmelighet, string $utsteder, string $konto): string
    {
        if ($utsteder === '' || $konto === '') {
            throw new \InvalidArgumentException('Utsteder og konto kreves.');
        }
        $t = $this->totp($hemmelighet);
        $t->setLabel($konto);
        $t->setIssuer($utsteder);

        return $t->getProvisioningUri();
    }

    /**
     * Kontrollerer koden. Gir tidssteget som ble brukt (til lagring), eller null når koden er feil eller steget alt
     * er brukt.
     */
    public function kontroller(string $hemmelighet, string $kode, ?int $sisteBrukteSteg): ?int
    {
        $kode = (string) preg_replace('/\s+/', '', $kode);
        if (preg_match('/^\d{' . self::SIFRE . '}$/D', $kode) !== 1) {
            return null;
        }
        $t = $this->totp($hemmelighet);
        $naa = $this->klokke->now()->getTimestamp();
        $steg = intdiv($naa, self::PERIODE);
        // Prøv alle stegene i vinduet (alltid tre sammenligninger, så tiden ikke røper hvilket som stemte).
        $treff = null;
        foreach ([$steg - 1, $steg, $steg + 1] as $s) {
            if ($s >= 0 && hash_equals($t->at($s * self::PERIODE), $kode) && $treff === null) {
                $treff = $s;
            }
        }
        if ($treff === null || ($sisteBrukteSteg !== null && $treff <= $sisteBrukteSteg)) {
            return null;
        }

        return $treff;
    }

    /** Koden for et tidspunkt (til tester og til å vise at oppsettet virker). */
    public function kode(string $hemmelighet, ?int $tid = null): string
    {
        return $this->totp($hemmelighet)->at(max(0, $tid ?? $this->klokke->now()->getTimestamp()));
    }

    private function totp(string $hemmelighet): OtpTotp
    {
        if ($hemmelighet === '') {
            throw new \InvalidArgumentException('Hemmeligheten kan ikke være tom.');
        }

        return OtpTotp::createFromSecret($hemmelighet, $this->klokke);
    }
}
