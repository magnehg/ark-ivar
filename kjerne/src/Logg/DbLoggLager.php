<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;

/**
 * Loggen i en databasetabell (bivrost_logg for Bivrost; en app kan bruke en egen tabell med samme form, se
 * tabellSql()). Hele hendelsen står som JSON i «hendelse»; kolonnene ved siden av er for filter og indekser.
 *
 * Bare tillegg: klassen har ingen UPDATE. Det eneste som slettes, er sikkerhetsloggen etter oppbevaringstiden. I
 * produksjon bør databasebrukeren i tillegg ikke ha UPDATE på tabellen (se docs/asvs.md, V7.3).
 */
final class DbLoggLager implements LoggLager
{
    public function __construct(private readonly Db $db, private readonly string $tabell = 'bivrost_logg')
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,60}$/D', $tabell) !== 1) {
            throw new \InvalidArgumentException('Ugyldig tabellnavn.');
        }
    }

    /** SQL for tabellen (brukes i Bivrosts migrering og av appene for sin egen faglogg). */
    public static function tabellSql(string $tabell): string
    {
        return "CREATE TABLE IF NOT EXISTS `$tabell` (
            nr BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id CHAR(36) NOT NULL,
            tid DATETIME(3) NOT NULL,
            kategori VARCHAR(20) NOT NULL,
            hva VARCHAR(200) NOT NULL,
            hvem_id VARCHAR(191) NULL,
            objekt_id VARCHAR(191) NULL,
            korrelasjon CHAR(36) NOT NULL,
            apper VARCHAR(500) NOT NULL DEFAULT '',
            hendelse JSON NOT NULL,
            UNIQUE KEY {$tabell}_id (id),
            KEY {$tabell}_tid (tid),
            KEY {$tabell}_hvem (hvem_id),
            KEY {$tabell}_objekt (objekt_id),
            KEY {$tabell}_korrelasjon (korrelasjon),
            KEY {$tabell}_hva (hva)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public function skriv(Hendelse $h): void
    {
        $apper = array_map(static fn (array $a): string => $a['id'], $h->apper);
        $this->db->utfor(
            "INSERT INTO `{$this->tabell}` (id, tid, kategori, hva, hvem_id, objekt_id, korrelasjon, apper, hendelse)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $h->id,
                str_replace(['T', 'Z'], [' ', ''], $h->tid),
                $h->kategori['kode'],
                $h->hva['urn'],
                $h->hvem['id'],
                $h->objekt['id'] ?? null,
                $h->korrelasjon,
                $apper === [] ? '' : ',' . implode(',', $apper) . ',',
                json_encode($h->tilListe(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
        );
    }

    /** @return array{0: string, 1: list<scalar|null>} */
    private function hvor(LoggFilter $f): array
    {
        $hvor = ['1=1'];
        $p = [];
        if ($f->app !== null) {
            $hvor[] = 'apper LIKE ?';
            $p[] = '%,' . addcslashes($f->app, '%_\\') . ',%';
        }
        if ($f->bruker !== null) {
            $hvor[] = '(hvem_id = ? OR objekt_id = ?)';
            $p[] = $f->bruker;
            $p[] = $f->bruker;
        }
        if ($f->fra !== null) {
            $hvor[] = 'tid >= ?';
            $p[] = $f->fra . ' 00:00:00';
        }
        if ($f->til !== null) {
            $hvor[] = 'tid < ?';
            $p[] = (new \DateTimeImmutable($f->til . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
        }
        if ($f->type !== null) {
            $hvor[] = 'hva LIKE ?';
            $p[] = addcslashes($f->type, '%_\\') . '%';
        }
        if ($f->korrelasjon !== null) {
            $hvor[] = 'korrelasjon = ?';
            $p[] = $f->korrelasjon;
        }
        if ($f->kategori !== null) {
            $hvor[] = 'kategori = ?';
            $p[] = $f->kategori;
        }

        return [implode(' AND ', $hvor), $p];
    }

    public function les(LoggFilter $filter, int $antall = 200, ?int $forNr = null): array
    {
        [$hvor, $p] = $this->hvor($filter);
        if ($forNr !== null) {
            $hvor .= ' AND nr < ?';
            $p[] = $forNr;
        }
        $antall = max(1, min(1000, $antall));
        $ut = [];
        foreach ($this->db->alle("SELECT nr, hendelse FROM `{$this->tabell}` WHERE $hvor ORDER BY nr DESC LIMIT $antall", $p) as $r) {
            $ut[] = ['nr' => Felt::tall($r['nr']), 'hendelse' => self::tolk($r['hendelse'])];
        }

        return $ut;
    }

    public function hverEn(LoggFilter $filter, callable $hver): int
    {
        [$hvor, $p] = $this->hvor($filter);
        $n = 0;
        $etter = 0;
        // I biter på 500, så minnebruken holder seg lav også for en stor logg.
        do {
            $rader = $this->db->alle(
                "SELECT nr, hendelse FROM `{$this->tabell}` WHERE $hvor AND nr > ? ORDER BY nr ASC LIMIT 500",
                array_merge($p, [$etter]),
            );
            foreach ($rader as $r) {
                $hver(self::tolk($r['hendelse']));
                $etter = Felt::tall($r['nr']);
                $n++;
            }
        } while (count($rader) === 500);

        return $n;
    }

    public function ryddSikkerhetslogg(int $dager): int
    {
        $dager = max(30, $dager);
        $grense = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-$dager days")->format('Y-m-d H:i:s');

        return $this->db->utfor("DELETE FROM `{$this->tabell}` WHERE kategori = 'sikkerhet' AND tid < ?", [$grense]);
    }

    private static function tolk(mixed $json): Hendelse
    {
        $d = json_decode(is_string($json) ? $json : '{}', true, 32, JSON_THROW_ON_ERROR);

        if (!is_array($d)) {
            $d = [];
        }
        $ut = [];
        foreach ($d as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return Hendelse::fraListe($ut);
    }
}
