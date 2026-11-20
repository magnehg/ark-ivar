<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Database;

use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Deler en SQL-tekst i setninger på «;» utenfor tekstverdier ('…', "…"), navn (`…`) og kommentarer. Brukes av
 * migreringene og innlesingen av sikkerhetskopier. Kommentarer (-- , # og /* *\/) tas bort, men /*! … *\/ og
 * /*+ … *\/ beholdes, fordi MySQL og MariaDB kjører dem. DELIMITER (triggere og prosedyrer) støttes ikke.
 */
final class SqlDeler
{
    /** @return list<string> */
    public static function del(string $sql): array
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }
        $ut = [];
        $na = '';
        $n = strlen($sql);
        $i = 0;
        $legg = static function (string $s) use (&$ut): void {
            $s = trim($s);
            if ($s === '') {
                return;
            }
            if (preg_match('/^DELIMITER\b/i', $s) === 1) {
                throw new BivrostFeil('bivrost.feil.sql.delimiter');
            }
            $ut[] = $s;
        };
        $linje = static fn (int $p): int => substr_count($sql, "\n", 0, $p) + 1;
        while ($i < $n) {
            $k = strcspn($sql, "'\"`#-/;", $i);
            if ($k > 0) {
                $na .= substr($sql, $i, $k);
                $i += $k;
                continue;
            }
            $c = $sql[$i];
            $neste = $sql[$i + 1] ?? '';
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while (true) {
                    if ($j >= $n) {
                        throw new BivrostFeil('bivrost.feil.sql.uavsluttet', ['linje' => $linje($i)]);
                    }
                    $d = $sql[$j];
                    if ($d === '\\' && $c !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($d === $c) {
                        if (($sql[$j + 1] ?? '') === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $na .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if ($c === '#' || ($c === '-' && $neste === '-' && ($i + 2 >= $n || ord($sql[$i + 2]) <= 32))) {
                $i += strcspn($sql, "\r\n", $i);
                continue;
            }
            if ($c === '/' && $neste === '*') {
                $j = strpos($sql, '*/', $i + 2);
                if ($j === false) {
                    throw new BivrostFeil('bivrost.feil.sql.uavsluttet', ['linje' => $linje($i)]);
                }
                $tegn = $sql[$i + 2] ?? '';
                $na .= ($tegn === '!' || $tegn === '+' || substr($sql, $i + 2, 2) === 'M!') ? substr($sql, $i, $j + 2 - $i) : ' ';
                $i = $j + 2;
                continue;
            }
            if ($c === ';') {
                $legg($na);
                $na = '';
                $i++;
                continue;
            }
            $na .= $c;
            $i++;
        }
        $legg($na);

        return $ut;
    }

    /** Kjører alle setningene og leser alle resultatsett, så tilkoblingen er klar for neste setning. */
    public static function kjor(Db $db, string $sql): void
    {
        foreach (self::del($sql) as $setning) {
            $st = $db->pdo()->query($setning);
            if ($st instanceof \PDOStatement) {
                do {
                    if ($st->columnCount() > 0) {
                        $st->fetchAll();
                    }
                } while ($st->nextRowset());
                $st->closeCursor();
            }
        }
    }
}
