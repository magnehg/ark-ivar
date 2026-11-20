<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Innlogging;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Begrensning av forsøk (ASVS V2.2.1, BIV-AUT-06): mislykkede forsøk telles per område og nøkkel (f.eks.
 * brukernavn eller IP-adresse) i et glidende vindu. Nøkkelen lagres bare som HMAC, så tabellen ikke inneholder
 * brukernavn eller IP-adresser i klartekst. Gamle rader slettes når de er eldre enn det lengste vinduet.
 */
final class Forsok
{
    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke,
        private readonly string $hemmelighet,
    ) {
    }

    private function hash(string $omraade, string $nokkel): string
    {
        return hash_hmac('sha256', $omraade . "\0" . mb_strtolower($nokkel, 'UTF-8'), $this->hemmelighet);
    }

    public function registrer(string $omraade, string $nokkel): void
    {
        $this->db->utfor(
            'INSERT INTO bivrost_forsok (omraade, nokkel_hash, tid) VALUES (?, ?, ?)',
            [$omraade, $this->hash($omraade, $nokkel), Db::tid($this->klokke->now())],
        );
    }

    public function antall(string $omraade, string $nokkel, int $sekunder): int
    {
        return Felt::tall($this->db->verdi(
            'SELECT COUNT(*) FROM bivrost_forsok WHERE omraade = ? AND nokkel_hash = ? AND tid > ?',
            [$omraade, $this->hash($omraade, $nokkel), Db::tid($this->klokke->now()->modify("-$sekunder seconds"))],
        ));
    }

    /** Sekunder til det eldste forsøket i vinduet faller ut. */
    private function ventetid(string $omraade, string $nokkel, int $sekunder): int
    {
        $eldste = $this->db->verdi(
            'SELECT MIN(tid) FROM bivrost_forsok WHERE omraade = ? AND nokkel_hash = ? AND tid > ?',
            [$omraade, $this->hash($omraade, $nokkel), Db::tid($this->klokke->now()->modify("-$sekunder seconds"))],
        );
        if (!is_string($eldste)) {
            return 1;
        }
        $t = new \DateTimeImmutable($eldste, new \DateTimeZone('UTC'));

        return max(1, $t->getTimestamp() + $sekunder - $this->klokke->now()->getTimestamp());
    }

    public function nullstill(string $omraade, string $nokkel): void
    {
        $this->db->utfor('DELETE FROM bivrost_forsok WHERE omraade = ? AND nokkel_hash = ?', [$omraade, $this->hash($omraade, $nokkel)]);
    }

    /**
     * Kaster HttpFeil 429 med Retry-After når en av grensene er nådd.
     *
     * @param list<array{0: string, 1: int, 2: int}> $grenser [nøkkel, høyst antall, vindu i sekunder]
     */
    public function sjekk(string $omraade, array $grenser): void
    {
        foreach ($grenser as [$nokkel, $maks, $sekunder]) {
            if ($this->antall($omraade, $nokkel, $sekunder) >= $maks) {
                $vent = $this->ventetid($omraade, $nokkel, $sekunder);
                throw new HttpFeil(429, 'for_mange_forsok', 'bivrost.feil.innlogging.for_mange', ['sekunder' => $vent], ['Retry-After' => (string) $vent]);
            }
        }
    }

    /** Sletter forsøk eldre enn $sekunder (standard ett døgn). */
    public function rydd(int $sekunder = 86400): int
    {
        return $this->db->utfor('DELETE FROM bivrost_forsok WHERE tid < ?', [Db::tid($this->klokke->now()->modify("-$sekunder seconds"))]);
    }
}
