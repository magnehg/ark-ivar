<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Migrering;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\SqlDeler;
use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Migreringsmotoren (DRF-02, DRF-12, BIV-DRF-04): én rekke per pakke (Bivrost Kjerne, appen, senere andre pakker),
 * hver med sin egen skjemaversjon i tabellen bivrost_skjemaversjon (pakke, versjon).
 *
 * Regler:
 *  - Filene heter NNN_navn.sql (tre eller flere sifre, små bokstaver a–z, tall og _) og kjøres i rekkefølge.
 *  - Hver fil skal kunne kjøres flere ganger (IF NOT EXISTS), fordi MySQL og MariaDB ikke kan rulle tilbake
 *    endringer i tabellstrukturen.
 *  - En migrering som er kjørt, endres aldri: sjekksummen (SHA-256) lagres, og en endret fil stopper alle
 *    migreringene i pakken med en tydelig melding. Rett med en ny migrering.
 *  - En navngitt lås (GET_LOCK) per database og pakke hindrer to samtidige kjøringer.
 *  - Doble numre eller .sql-filer med feil navn stopper kjøringen før noe er gjort.
 */
final class Migrator
{
    public const MONSTER = '/^(\d{3,})_([a-z0-9_]+)\.sql$/D';

    public function __construct(
        private readonly Db $db,
        private readonly string $pakke,
        private readonly string $mappe,
        private readonly int $ventSekunder = 30,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $pakke) !== 1) {
            throw new \InvalidArgumentException('Ugyldig pakkenavn.');
        }
    }

    public function pakke(): string
    {
        return $this->pakke;
    }

    public function sikreTabell(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS bivrost_skjemaversjon (
            pakke VARCHAR(40) NOT NULL,
            versjon INT NOT NULL,
            navn VARCHAR(200) NOT NULL,
            sjekksum CHAR(64) NOT NULL,
            kjort DATETIME(6) NOT NULL,
            PRIMARY KEY (pakke, versjon)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /**
     * Leser mappen uten å kaste.
     *
     * @return array{filer: array<int, array{nr: int, navn: string, fil: string}>, feil: list<string>}
     */
    private function les(): array
    {
        $feil = [];
        $per = [];
        $filer = is_dir($this->mappe) ? Felt::ellers(scandir($this->mappe), []) : [];
        sort($filer, SORT_STRING);
        foreach ($filer as $navn) {
            $fil = $this->mappe . '/' . $navn;
            if (!is_file($fil) || strtolower(pathinfo($navn, PATHINFO_EXTENSION)) !== 'sql') {
                continue;
            }
            if (preg_match(self::MONSTER, $navn, $m) !== 1) {
                $feil[] = 'navn:' . $navn;
                continue;
            }
            $nr = (int) $m[1];
            if (isset($per[$nr])) {
                $feil[] = 'dobbel:' . $nr;
                continue;
            }
            $per[$nr] = ['nr' => $nr, 'navn' => str_replace('_', ' ', $m[2]), 'fil' => $fil];
        }
        ksort($per);

        return ['filer' => $per, 'feil' => $feil];
    }

    /**
     * Status for pakken: versjonen i databasen, siste fil, ventende, feil (filnavn, doble numre, endrede migreringer)
     * og versjoner i databasen uten fil (etter en nedgradering).
     *
     * @return array{pakke: string, versjon: int, siste: int, ventende: list<array{nr: int, navn: string}>,
     *     feil: list<string>, ukjente_i_db: list<int>}
     */
    public function status(): array
    {
        $this->sikreTabell();
        $kjort = [];
        foreach ($this->db->alle('SELECT versjon, sjekksum FROM bivrost_skjemaversjon WHERE pakke = ?', [$this->pakke]) as $r) {
            $kjort[Felt::tall($r['versjon'])] = Felt::tekst($r['sjekksum']);
        }
        $l = $this->les();
        $feil = $l['feil'];
        foreach ($l['filer'] as $nr => $m) {
            if (isset($kjort[$nr]) && !hash_equals($kjort[$nr], (string) hash_file('sha256', $m['fil']))) {
                $feil[] = 'endret:' . basename($m['fil']);
            }
        }
        $ventende = [];
        if ($feil === []) {
            foreach ($l['filer'] as $nr => $m) {
                if (!isset($kjort[$nr])) {
                    $ventende[] = ['nr' => $nr, 'navn' => $m['navn']];
                }
            }
        }
        $ukjente = array_values(array_diff(array_keys($kjort), array_keys($l['filer'])));
        sort($ukjente);

        return [
            'pakke' => $this->pakke,
            'versjon' => $kjort === [] ? 0 : max(array_keys($kjort)),
            'siste' => $l['filer'] === [] ? 0 : array_key_last($l['filer']),
            'ventende' => $ventende,
            'feil' => $feil,
            'ukjente_i_db' => $ukjente,
        ];
    }

    /**
     * Kjører ventende migreringer og gir dem som ble kjørt. Kaster BivrostFeil når en annen kjøring pågår, når filene
     * er i ustand, og når en migrering feiler (de som ble kjørt før den, står i databasen).
     *
     * @param (callable(): void)|null $forKjoring Kalles mens låsen holdes og før første migrering, men bare når noe
     *     venter (sikkerhetskopien tas her, så den er konsistent med migreringene, L-015).
     * @return list<array{nr: int, navn: string}>
     */
    public function kjor(?callable $forKjoring = null): array
    {
        $this->sikreTabell();
        if (!$this->db->las('migr:' . $this->pakke, $this->ventSekunder)) {
            throw new BivrostFeil('bivrost.feil.migrering.pagar', ['pakke' => $this->pakke]);
        }
        try {
            $status = $this->status();
            if ($status['feil'] !== []) {
                throw new BivrostFeil('bivrost.feil.migrering.filer', ['pakke' => $this->pakke, 'feil' => implode(', ', $status['feil'])]);
            }
            if ($status['ventende'] === []) {
                return [];
            }
            if ($forKjoring !== null) {
                $forKjoring();
            }
            $filer = $this->les()['filer'];
            $kjort = [];
            foreach ($status['ventende'] as $v) {
                $m = $filer[$v['nr']];
                $sql = @file_get_contents($m['fil']);
                if ($sql === false) {
                    throw new BivrostFeil('bivrost.feil.migrering.les', ['fil' => basename($m['fil'])]);
                }
                try {
                    SqlDeler::kjor($this->db, $sql);
                    $this->db->utfor(
                        'INSERT INTO bivrost_skjemaversjon (pakke, versjon, navn, sjekksum, kjort) VALUES (?, ?, ?, ?, ?)',
                        [$this->pakke, $m['nr'], $m['navn'], hash('sha256', $sql), Db::tid()],
                    );
                } catch (\Throwable $e) {
                    // En migrering kan være én transaksjon (START TRANSACTION … COMMIT): rull tilbake det som er åpent.
                    try {
                        $this->db->pdo()->exec('ROLLBACK');
                    } catch (\Throwable) {
                        // Skal ikke skjule den opprinnelige feilen.
                    }
                    throw new BivrostFeil('bivrost.feil.migrering.feilet', ['fil' => basename($m['fil'])], $e);
                }
                $kjort[] = ['nr' => $m['nr'], 'navn' => $m['navn']];
            }

            return $kjort;
        } finally {
            $this->db->slippLas('migr:' . $this->pakke);
        }
    }
}
