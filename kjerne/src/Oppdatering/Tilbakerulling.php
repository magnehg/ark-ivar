<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Logg\DbLoggLager;
use Bivrost\Kjerne\Logg\Hendelse;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Logg\Verdi;
use Bivrost\Kjerne\Sikkerhetskopi\Databasekopi;
use Bivrost\Kjerne\Sikkerhetskopi\Filkopi;
use Bivrost\Kjerne\Sikkerhetskopi\Kopilager;

/**
 * Tilbake til et gjenopprettingspunkt med ett kall (L-012, BIV-DRF-08): filene i pakkenes mapper og databasen
 * sammen, under oppdateringslåsen. Etterpå holdes automatisk oppdatering (API-et) igjen til en med drift slår den
 * på igjen, så neste kjøring ikke legger inn den samme versjonen på nytt.
 *
 * Revisjonsloggen er bare tillegg og skal ikke rulles tilbake med databasen: hendelsene etter punktet i bivrost_logg
 * og i appens loggtabeller (AppKonfig logg.tabeller) leses før innlesingen og legges inn igjen etterpå (BIV-LOG-05,
 * BIV-B-008). De skrives rett i tabellen (DbLoggLager), ikke gjennom Logg, så de ikke sendes til syslog en gang til.
 * Finnes ikke appens loggtabell ved punktet (den ble laget av en senere migrering), lages den med
 * DbLoggLager::tabellSql(), så hendelsene har et sted å stå. Det samme gjelder de klarerte utgivernøklene: et bytte av
 * nøkkel går bare fremover (BIV-B-034), så en tilbakerulling gjør aldri en nøkkel som er byttet ut (kanskje fordi den
 * er lekket), klarert igjen, og et signaturkrav faller aldri bort.
 */
final class Tilbakerulling
{
    /** @param list<string> $apploggtabeller Appens loggtabeller (AppKonfig::$loggtabeller). */
    public function __construct(
        private readonly string $drift,
        private readonly Db $db,
        private readonly Logg $logg,
        private readonly Databasekopi $dbKopi,
        private readonly Filkopi $filKopi,
        private readonly Kopilager $lager,
        private readonly Oppdaterer $oppdaterer,
        private readonly Utgivernokler $utgivere,
        private readonly array $apploggtabeller = [],
    ) {
    }

    /** @return list<array{navn: string, laget: string, grunn: string, pakker: list<array{id: string, versjon: string}>}> */
    public function punkter(): array
    {
        $ut = [];
        foreach ($this->lager->liste() as $k) {
            if ($k['slag'] !== 'punkt') {
                continue;
            }
            try {
                $p = $this->les($k['navn']);
            } catch (BivrostFeil) {
                continue;
            }
            $ut[] = ['navn' => $k['navn'], 'laget' => $p['laget'], 'grunn' => $p['grunn'],
                'pakker' => array_map(static fn (array $x): array => ['id' => $x['id'], 'versjon' => $x['versjon']], $p['pakker'])];
        }

        return $ut;
    }

    /**
     * @return array{laget: string, grunn: string, db: string, filer: string, pakker: list<array{id: string, mappe: string, versjon: string}>}
     */
    private function les(string $navn): array
    {
        $j = json_decode((string) @file_get_contents($this->lager->sti($navn)), true);
        if (
            !is_array($j) || ($j['format'] ?? null) !== 'bivrost-gjenopprettingspunkt' || !is_string($j['db'] ?? null) || !is_string($j['filer'] ?? null)
            || !is_string($j['laget'] ?? null) || !is_array($j['pakker'] ?? null)
        ) {
            throw new BivrostFeil('bivrost.feil.tilbake.punkt');
        }
        $pakker = [];
        foreach ($j['pakker'] as $p) {
            if (
                !is_array($p) || !is_string($p['id'] ?? null) || !is_string($p['mappe'] ?? null) || !is_string($p['versjon'] ?? null)
                || preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*/$#D', $p['mappe']) !== 1
            ) {
                throw new BivrostFeil('bivrost.feil.tilbake.punkt');
            }
            $pakker[] = ['id' => $p['id'], 'mappe' => $p['mappe'], 'versjon' => $p['versjon']];
        }

        return ['laget' => $j['laget'], 'grunn' => is_string($j['grunn'] ?? null) ? $j['grunn'] : '', 'db' => $j['db'], 'filer' => $j['filer'], 'pakker' => $pakker];
    }

    /**
     * Legger tilbake filene og databasen fra punktet $navn, og deretter loggene etter punktet.
     *
     * @return array{punkt: string, avvik: list<string>, logg_lagt_tilbake: int, logg_per_tabell: array<string, int>}
     */
    public function tilbakerull(string $navn): array
    {
        $p = $this->les($navn);
        $las = @fopen($this->drift . '/oppdatering/las', 'c');
        if ($las === false || !flock($las, LOCK_EX | LOCK_NB)) {
            throw new HttpFeil(409, 'oppdatering_pagar', 'bivrost.feil.oppdatering.pagar');
        }
        try {
            @set_time_limit(300);
            // Loggene etter punktet tas vare på (alt fra og med tiden punktet ble laget).
            $senere = $this->hendelserEtter(str_replace(['T', 'Z'], [' ', ''], $p['laget']));
            $nokler = $this->utgivere->taVare();
            $mapper = array_map(static fn (array $x): string => $x['mappe'], $p['pakker']);
            $avvik = $this->filKopi->leggTilbake($p['filer'], $mapper);
            $this->dbKopi->lesInn($p['db']);
            $this->utgivere->leggTilbake($nokler);
            $perTabell = $this->leggTilbakeHendelser($senere);
            $lagt = array_sum($perTabell);
            @unlink($this->drift . '/oppdatering/tilstand.json');
            $this->oppdaterer->holdIgjen(true);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        } finally {
            flock($las, LOCK_UN);
            fclose($las);
        }
        foreach ($p['pakker'] as $x) {
            $this->logg->skriv(
                'oppdatering:tilbakerullet',
                new LoggObjekt(null, 'pakke', $this->logg->tekst('bivrost.objekttype.pakke'), $x['id'], $x['id']),
                [],
                ['versjon' => Verdi::tekst($x['versjon'])],
                ['punkt' => $navn, 'avvik' => array_slice($avvik, 0, 50), 'logg_lagt_tilbake' => $lagt, 'logg_per_tabell' => $perTabell, 'utgivernokler_beholdt' => count($nokler)]
            );
        }

        return ['punkt' => $navn, 'avvik' => $avvik, 'logg_lagt_tilbake' => $lagt, 'logg_per_tabell' => $perTabell];
    }

    /**
     * Hendelsene fra og med $tid (UTC, databaseformen) i bivrost_logg og appens loggtabeller, per tabell. En
     * loggtabell som ikke finnes, hoppes over.
     *
     * @return array<string, list<Hendelse>>
     */
    private function hendelserEtter(string $tid): array
    {
        $ut = [];
        foreach (array_merge(['bivrost_logg'], $this->apploggtabeller) as $tabell) {
            $ut[$tabell] = [];
            if (!$this->db->harTabell($tabell)) {
                continue;
            }
            // Tabellnavnet er kontrollert i AppKonfig (bare [a-z0-9_] og appens prefiks).
            foreach ($this->db->alle("SELECT hendelse FROM `$tabell` WHERE tid >= ? ORDER BY nr", [$tid]) as $r) {
                $d = json_decode(Felt::tekst($r['hendelse']), true);
                if (is_array($d)) {
                    /** @var array<string, mixed> $d */
                    $ut[$tabell][] = Hendelse::fraListe($d);
                }
            }
        }

        return $ut;
    }

    /**
     * Skriver hendelsene inn igjen etter innlesingen. En hendelse som finnes fra før (samme ID, f.eks. fordi den var
     * med i punktet), hoppes over. Gir antallet som ble lagt inn, per tabell.
     *
     * @param array<string, list<Hendelse>> $senere
     * @return array<string, int>
     */
    private function leggTilbakeHendelser(array $senere): array
    {
        $ut = [];
        foreach ($senere as $tabell => $hendelser) {
            $ut[$tabell] = 0;
            if ($hendelser === []) {
                continue;
            }
            if (!$this->db->harTabell($tabell)) {
                $this->db->utfor(DbLoggLager::tabellSql($tabell));
            }
            $lager = new DbLoggLager($this->db, $tabell);
            foreach ($hendelser as $h) {
                try {
                    $lager->skriv($h);
                    $ut[$tabell]++;
                } catch (\PDOException) {
                    // Finnes alt (samme ID): hoppes over.
                }
            }
        }

        return $ut;
    }
}
