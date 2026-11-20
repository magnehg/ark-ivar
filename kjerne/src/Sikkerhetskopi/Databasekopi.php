<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sikkerhetskopi;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Sikkerhetskopi av databasen i PHP (webhotell har ofte ikke mysqldump), BIV-DRF-09, L-015:
 *  - bare installasjonens egne tabeller (prefikset bivrost_ og appens prefikser), aldri andre programmers tabeller
 *    i en delt database
 *  - ett konsistent øyeblikksbilde (START TRANSACTION WITH CONSISTENT SNAPSHOT)
 *  - verdiene som tekst fra tekstprotokollen (eksakte tall), binære kolonner og BIT som heksadesimalt (0x…)
 *  - gzip, skrevet til en .tmp-fil, lest tilbake og kontrollert før den får navnet
 * Hver setning avsluttes med en egen linje «-- bivrost:setning», så innlesingen kan gå setning for setning uten å
 * lese hele filen inn i minnet.
 */
final class Databasekopi
{
    public const SKILLE = "\n-- bivrost:setning\n";
    private const SLUTT = '-- bivrost:slutt';

    /** @param list<string> $prefikser Med bivrost_ først. */
    public function __construct(private readonly Db $db, private readonly array $prefikser, private readonly Kopilager $lager)
    {
    }

    /** @return list<string> Installasjonens tabeller (bare de med et av prefiksene). */
    public function tabeller(): array
    {
        $ut = [];
        foreach ($this->db->alle("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $r) {
            $t = Felt::tekst($r['t']);
            foreach ($this->prefikser as $p) {
                if (str_starts_with($t, $p)) {
                    $ut[] = $t;
                    break;
                }
            }
        }

        return $ut;
    }

    /** Tar kopien og gir filnavnet. */
    public function ta(): string
    {
        @set_time_limit(300);
        $navn = $this->lager->nyttNavn('db', '.sql.gz');
        $fil = $this->lager->mappe() . '/' . $navn;
        $tmp = "$fil.tmp";
        $gammel = umask(0077);
        $f = @fopen($tmp, 'xb');
        umask($gammel);
        if ($f === false) {
            throw new BivrostFeil('bivrost.feil.kopi.skriv');
        }
        $gz = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        if ($gz === false) {
            fclose($f);
            @unlink($tmp);
            throw new BivrostFeil('bivrost.feil.kopi.skriv');
        }
        $skriv = static function (string $s, bool $slutt = false) use ($f, $gz): void {
            $d = deflate_add($gz, $s, $slutt ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if ($d === false || ($d !== '' && fwrite($f, $d) !== strlen($d))) {
                throw new BivrostFeil('bivrost.feil.kopi.skriv');
            }
        };
        $pdo = $this->db->pdo();
        $emuler = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
        $tekst = $pdo->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES);
        $ok = false;
        try {
            // Tekstprotokollen og strenger gir eksakte verdier (DOUBLE, DECIMAL, store tall).
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
            $egen = !$pdo->inTransaction();
            if ($egen) {
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            }
            $skriv('-- Bivrost: sikkerhetskopi av databasen ' . $this->db->databasenavn() . ', ' . gmdate('Y-m-d H:i:s') . " UTC\n");
            $skriv('SET NAMES utf8mb4' . self::SKILLE . 'SET FOREIGN_KEY_CHECKS = 0' . self::SKILLE);
            foreach ($this->tabeller() as $t) {
                $opprett = $this->db->en("SHOW CREATE TABLE `$t`");
                $sql = is_array($opprett) ? Felt::tekst($opprett['Create Table'] ?? '') : '';
                $skriv("DROP TABLE IF EXISTS `$t`" . self::SKILLE . $sql . self::SKILLE);
                $kol = $this->db->alle('SELECT COLUMN_NAME AS n, DATA_TYPE AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]);
                $uttrykk = [];
                $binaer = [];
                foreach ($kol as $k) {
                    $n = str_replace('`', '``', Felt::tekst($k['n']));
                    $d = strtolower(Felt::tekst($k['d']));
                    $b = in_array($d, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon'], true);
                    $binaer[] = $b;
                    $uttrykk[] = $b ? "HEX(`$n`)" : "`$n`";
                }
                $st = $pdo->query('SELECT ' . implode(', ', $uttrykk) . " FROM `$t`");
                if ($st === false) {
                    throw new BivrostFeil('bivrost.feil.kopi.les');
                }
                $rader = [];
                while (($r = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                    /** @var list<string|null> $r */
                    $v = [];
                    foreach ($r as $i => $x) {
                        $v[] = $x === null ? 'NULL' : (($binaer[$i] ?? false) ? ($x === '' ? "''" : '0x' . $x) : $pdo->quote($x));
                    }
                    $rader[] = '(' . implode(',', $v) . ')';
                    if (count($rader) >= 200) {
                        $skriv("INSERT INTO `$t` VALUES " . implode(',', $rader) . self::SKILLE);
                        $rader = [];
                    }
                }
                $st->closeCursor();
                if ($rader !== []) {
                    $skriv("INSERT INTO `$t` VALUES " . implode(',', $rader) . self::SKILLE);
                }
            }
            $skriv('SET FOREIGN_KEY_CHECKS = 1' . self::SKILLE . self::SLUTT . "\n", true);
            if ($egen) {
                $pdo->exec('COMMIT');
            }
            $ok = true;
        } finally {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emuler);
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, $tekst);
            fclose($f);
            if (!$ok) {
                @unlink($tmp);
            }
        }
        if (!self::kontroller($tmp) || !@rename($tmp, $fil)) {
            @unlink($tmp);
            throw new BivrostFeil('bivrost.feil.kopi.kontroll');
        }

        return $navn;
    }

    /** Leser filen til slutten og sjekker at sluttmerket står der (kopien er hel). */
    public static function kontroller(string $fil): bool
    {
        $sist = '';
        foreach (self::setninger($fil) as $s) {
            $sist = $s;
        }

        return trim($sist) === self::SLUTT;
    }

    /**
     * Setningene i en kopi, én om gangen (generator, lite minne).
     *
     * @return \Generator<int, string>
     */
    public static function setninger(string $fil): \Generator
    {
        $f = @gzopen($fil, 'rb');
        if ($f === false) {
            throw new BivrostFeil('bivrost.feil.kopi.les');
        }
        try {
            $na = '';
            while (!gzeof($f)) {
                $linje = gzgets($f, 1024 * 1024 * 16);
                if ($linje === false) {
                    break;
                }
                if ($linje === "-- bivrost:setning\n") {
                    yield rtrim($na, "\n");
                    $na = '';
                    continue;
                }
                $na .= $linje;
            }
            if (trim($na) !== '') {
                yield $na;
            }
        } finally {
            gzclose($f);
        }
    }

    /**
     * Leser inn en kopi: sletter først installasjonens egne tabeller som ikke er i kopien (tabeller fra en nyere
     * versjon), så kjøres setningene. Rører aldri tabeller uten et av prefiksene.
     */
    public function lesInn(string $navn): void
    {
        $fil = $this->lager->sti($navn);
        if (!self::kontroller($fil)) {
            throw new BivrostFeil('bivrost.feil.kopi.kontroll');
        }
        @set_time_limit(300);
        $iKopien = [];
        foreach (self::setninger($fil) as $s) {
            if (preg_match('/^CREATE TABLE `([^`]+)`/', $s, $m) === 1) {
                $iKopien[] = $m[1];
            }
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->tabeller() as $t) {
                if (!in_array($t, $iKopien, true)) {
                    $pdo->exec("DROP TABLE IF EXISTS `$t`");
                }
            }
            foreach (self::setninger($fil) as $s) {
                $s = trim($s);
                if ($s === '' || str_starts_with($s, '--')) {
                    $linjer = array_filter(explode("\n", $s), static fn (string $l): bool => !str_starts_with(trim($l), '--') && trim($l) !== '');
                    $s = implode("\n", $linjer);
                    if ($s === '') {
                        continue;
                    }
                }
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $s, $m) === 1 && !$this->egen($m[2])) {
                    throw new BivrostFeil('bivrost.feil.kopi.fremmed_tabell', ['tabell' => $m[2]]);
                }
                $pdo->exec($s);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function egen(string $t): bool
    {
        foreach ($this->prefikser as $p) {
            if (str_starts_with($t, $p)) {
                return true;
            }
        }

        return false;
    }
}
