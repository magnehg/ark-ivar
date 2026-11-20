<?php

declare(strict_types=1);

namespace ArkIvar\Brukere;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * Engangskoder sendt på e-post (arkivar_engangskode): bekreftelse av e-postadressen ved registrering og nytt
 * passord. Koden er lett å skrive av (åtte tegn, 40 bit), virker én gang og i kort tid, og bare SHA-256 av den
 * lagres. Den som kaller, begrenser antall forsøk (Forsok), så koden ikke kan gjettes.
 */
final class Engangskoder
{
    public const BEKREFT = 'bekreft';
    public const PASSORD = 'passord';

    /** Hvor lenge en kode virker. */
    public const GYLDIG_SEKUNDER = 1800;

    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke)
    {
    }

    /** Lager en ny kode for brukeren og formålet. Eldre koder for det samme slutter å virke. */
    public function ny(string $brukerId, string $formal): string
    {
        $kode = Tilfeldig::lesbarKode(8);
        $naa = $this->klokke->now();
        $this->db->utfor('DELETE FROM arkivar_engangskode WHERE bruker_id = ? AND formal = ?', [$brukerId, $formal]);
        $this->db->utfor(
            'INSERT INTO arkivar_engangskode (id, bruker_id, formal, kode_hash, utloper, opprettet) VALUES (?, ?, ?, ?, ?, ?)',
            [Tilfeldig::uuid(), $brukerId, $formal, self::hash($kode), Db::tid($naa->modify('+' . self::GYLDIG_SEKUNDER . ' seconds')), Db::tid($naa)],
        );

        return $kode;
    }

    /** Om koden stemmer, uten å bruke den opp (til å kontrollere før noe annet valideres). */
    public function stemmer(string $brukerId, string $formal, string $kode): bool
    {
        return $this->db->verdi(
            'SELECT 1 FROM arkivar_engangskode WHERE bruker_id = ? AND formal = ? AND kode_hash = ? AND brukt IS NULL AND utloper > ?',
            [$brukerId, $formal, self::hash($kode), Db::tid($this->klokke->now())],
        ) !== null;
    }

    /** Bruker koden. Gir false når den er feil, brukt eller utløpt. */
    public function bruk(string $brukerId, string $formal, string $kode): bool
    {
        $naa = Db::tid($this->klokke->now());

        return $this->db->utfor(
            'UPDATE arkivar_engangskode SET brukt = ? WHERE bruker_id = ? AND formal = ? AND kode_hash = ? AND brukt IS NULL AND utloper > ?',
            [$naa, $brukerId, $formal, self::hash($kode), $naa],
        ) === 1;
    }

    /** Sletter koder som er brukt eller utløpt for mer enn et døgn siden. */
    public function rydd(): int
    {
        return $this->db->utfor('DELETE FROM arkivar_engangskode WHERE utloper < ?', [Db::tid($this->klokke->now()->modify('-1 day'))]);
    }

    private static function hash(string $kode): string
    {
        return hash('sha256', Tilfeldig::normaliserKode($kode));
    }
}
