<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tilgang;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Logg\Verdi;
use Bivrost\Kjerne\Sprak\Tekster;

/**
 * Rolleregisteret (BIV-ROL-03): appen definerer fagrollene sine i konfigurasjonen (AppKonfig «roller»), og Bivrost
 * legger til sine egne tilganger (drift og brukeradministrator på tvers av appene, appadministrator og
 * brukeradministrator i appen). synk() fører definisjonen inn i tabellen bivrost_rolle og logger endringene:
 *
 *  - en ny rolle dukker opp som ny tilgang (uten kodeendring i Bivrost og uten migrering)
 *  - en rolle som fjernes fra konfigurasjonen, slettes ikke, men deaktiveres (aktiv = 0); den gir ingen tilgang
 *  - kommer den tilbake, aktiveres den igjen; navn og beskrivelse oppdateres
 *  - URN-en er den faste nøkkelen
 *
 * dokument() er det Bivrost Sentral leser for å vise appens roller (fase 2).
 */
final class Rolleregister
{
    private const HASH = 'rolleregister.hash';

    /** @var array<string, array{urn: string, slag: string, app: ?string, navn: string, tekst: array<string, string>, beskrivelse: array<string, string>}>|null */
    private ?array $definisjoner = null;

    public function __construct(
        private readonly Db $db,
        private readonly AppKonfig $app,
        private readonly Tekster $tekster,
    ) {
    }

    /**
     * Rollene og tilgangene slik konfigurasjonen og Bivrost definerer dem nå, med tekst på alle språk.
     *
     * @return array<string, array{urn: string, slag: string, app: ?string, navn: string, tekst: array<string, string>, beskrivelse: array<string, string>}>
     */
    public function definisjoner(): array
    {
        if ($this->definisjoner !== null) {
            return $this->definisjoner;
        }
        $ut = [];
        foreach (Urn::FELLES_TILGANGER as $n) {
            $urn = Urn::tilgang($n);
            $ut[$urn] = ['urn' => $urn, 'slag' => 'tilgang', 'app' => null, 'navn' => $n,
            'tekst' => $this->alleSprak("bivrost.tilgang.$n", []), 'beskrivelse' => $this->alleSprak("bivrost.tilgang.$n.beskrivelse", [])];
        }
        foreach (Urn::APP_TILGANGER as $n) {
            $urn = Urn::appTilgang($this->app->app, $n);
            $ut[$urn] = ['urn' => $urn, 'slag' => 'tilgang', 'app' => $this->app->app, 'navn' => $n,
                'tekst' => $this->alleSprak("bivrost.tilgang.app.$n", ['app' => $this->app->appnavn]),
                'beskrivelse' => $this->alleSprak("bivrost.tilgang.app.$n.beskrivelse", ['app' => $this->app->appnavn])];
        }
        foreach ($this->app->roller as $r) {
            $urn = Urn::rolle($this->app->app, $r['navn']);
            $tekst = [];
            foreach ($this->tekster->sprak() as $s) {
                $tekst[$s] = $r['tekst'][$s] ?? $r['tekst']['nb'];
            }
            $ut[$urn] = ['urn' => $urn, 'slag' => 'rolle', 'app' => $this->app->app, 'navn' => $r['navn'],
                'tekst' => $tekst, 'beskrivelse' => $r['beskrivelse']];
        }

        return $this->definisjoner = $ut;
    }

    /**
     * Teksten på alle språkene.
     *
     * @param array<string, string> $v
     * @return array<string, string>
     */
    private function alleSprak(string $nokkel, array $v): array
    {
        $t = [];
        foreach ($this->tekster->sprak() as $s) {
            $t[$s] = $this->tekster->tekst($nokkel, $v, $s);
        }

        return $t;
    }

    /** Navnet til visning: «Arkivar (Gerð)» for en fagrolle, teksten for en tilgang. Null når URN-en er ukjent. */
    public function navn(string $urn, string $sprak): ?string
    {
        $d = $this->definisjoner()[$urn] ?? null;
        $tekst = $d['tekst'][$sprak] ?? null;
        $slag = $d['slag'] ?? null;
        if ($d === null) {
            try {
                $r = $this->db->harTabell('bivrost_rolle') ? $this->db->en('SELECT slag, tekst FROM bivrost_rolle WHERE urn = ?', [$urn]) : null;
            } catch (\Throwable) {
                $r = null;
            }
            if ($r === null) {
                return null;
            }
            $t = json_decode(Felt::tekst($r['tekst']), true);
            $tekst = is_array($t) && is_string($t[$sprak] ?? null) ? $t[$sprak] : (is_array($t) && is_string($t['nb'] ?? null) ? $t['nb'] : null);
            $slag = Felt::tekst($r['slag']);
        }
        if ($tekst === null) {
            return null;
        }

        return $slag === 'rolle' ? $this->tekster->tekst('bivrost.rolle.visning', ['rolle' => $tekst, 'app' => $this->app->appnavn], $sprak) : $tekst;
    }

    /** Om URN-en finnes og er aktiv (en deaktivert rolle gir ingen tilgang). */
    public function aktiv(string $urn): bool
    {
        return isset($this->definisjoner()[$urn]);
    }

    /**
     * Fører definisjonen inn i databasen når den er endret (hash i bivrost_innstilling), og logger hver endring én gang.
     *
     * @return list<array{urn: string, endring: string}>
     */
    public function synk(Logg $logg): array
    {
        $def = $this->definisjoner();
        $hash = hash('sha256', json_encode($def, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $lagret = $this->db->verdi('SELECT verdi FROM bivrost_innstilling WHERE nokkel = ?', [self::HASH]);
        if ($lagret === $hash) {
            return [];
        }

        return $this->db->transaksjon(function (Db $db) use ($def, $hash, $logg): array {
            $endringer = [];
            $finnes = [];
            foreach ($db->alle('SELECT urn, app, slag, navn, tekst, beskrivelse, aktiv FROM bivrost_rolle WHERE app IS NULL OR app = ? FOR UPDATE', [$this->app->app]) as $r) {
                $finnes[Felt::tekst($r['urn'])] = $r;
            }
            $naa = Db::tid();
            foreach ($def as $urn => $d) {
                $tekst = json_encode($d['tekst'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $beskr = json_encode($d['beskrivelse'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $r = $finnes[$urn] ?? null;
                $objekt = new LoggObjekt($d['app'], $d['slag'], $this->tekster->tekst('bivrost.objekttype.' . $d['slag']), $urn, $d['tekst']['nb']);
                if ($r === null) {
                    $db->utfor(
                        'INSERT INTO bivrost_rolle (urn, app, slag, navn, tekst, beskrivelse, aktiv, opprettet, endret) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
                        [$urn, $d['app'], $d['slag'], $d['navn'], $tekst, $beskr, $naa, $naa],
                    );
                    $logg->skriv('rolleregister:ny', $objekt, [], ['navn' => Verdi::tekst($d['tekst']['nb'])]);
                    $endringer[] = ['urn' => $urn, 'endring' => 'ny'];
                    continue;
                }
                $gammelTekst = json_decode(Felt::tekst($r['tekst']), true);
                $tekstEndret = !self::likeTekster($gammelTekst, $d['tekst']) || !self::likeTekster(json_decode(Felt::tekst($r['beskrivelse']), true), $d['beskrivelse']);
                if (Felt::tall($r['aktiv']) === 0 || $tekstEndret) {
                    $db->utfor(
                        'UPDATE bivrost_rolle SET tekst = ?, beskrivelse = ?, aktiv = 1, endret = ?, deaktivert = NULL WHERE urn = ?',
                        [$tekst, $beskr, $naa, $urn],
                    );
                    $gammeltNavn = is_array($gammelTekst) && is_string($gammelTekst['nb'] ?? null) ? $gammelTekst['nb'] : '';
                    $endring = Felt::tall($r['aktiv']) === 0 ? 'gjenopprettet' : 'endret';
                    $logg->skriv("rolleregister:$endring", $objekt, ['navn' => Verdi::tekst($gammeltNavn)], ['navn' => Verdi::tekst($d['tekst']['nb'])]);
                    $endringer[] = ['urn' => $urn, 'endring' => $endring];
                }
            }
            foreach ($finnes as $urn => $r) {
                if (!isset($def[$urn]) && Felt::tall($r['aktiv']) === 1) {
                    $db->utfor('UPDATE bivrost_rolle SET aktiv = 0, endret = ?, deaktivert = ? WHERE urn = ?', [$naa, $naa, $urn]);
                    $t = json_decode(Felt::tekst($r['tekst']), true);
                    $navn = is_array($t) && is_string($t['nb'] ?? null) ? $t['nb'] : $urn;
                    $slag = Felt::tekst($r['slag']);
                    $logg->skriv(
                        'rolleregister:deaktivert',
                        new LoggObjekt(is_string($r['app']) ? $r['app'] : null, $slag, $this->tekster->tekst('bivrost.objekttype.' . $slag), $urn, $navn),
                        ['aktiv' => Verdi::kode(true, $this->tekster->tekst('bivrost.aktiv.ja'))],
                        ['aktiv' => Verdi::kode(false, $this->tekster->tekst('bivrost.aktiv.nei'))],
                    );
                    $endringer[] = ['urn' => $urn, 'endring' => 'deaktivert'];
                }
            }
            $db->utfor(
                'INSERT INTO bivrost_innstilling (nokkel, verdi, endret) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret = VALUES(endret)',
                [self::HASH, $hash, $naa],
            );

            return $endringer;
        });
    }

    /**
     * Alle radene i registeret for denne appen og de felles tilgangene, også de deaktiverte.
     *
     * @return list<array{urn: string, slag: string, app: ?string, navn: string, tekst: array<string, string>, beskrivelse: array<string, string>, aktiv: bool}>
     */
    public function alle(): array
    {
        $ut = [];
        foreach ($this->db->alle('SELECT urn, app, slag, navn, tekst, beskrivelse, aktiv FROM bivrost_rolle WHERE app IS NULL OR app = ? ORDER BY slag, urn', [$this->app->app]) as $r) {
            $ut[] = [
                'urn' => Felt::tekst($r['urn']),
                'slag' => Felt::tekst($r['slag']),
                'app' => is_string($r['app']) ? $r['app'] : null,
                'navn' => Felt::tekst($r['navn']),
                'tekst' => self::tekstliste($r['tekst']),
                'beskrivelse' => self::tekstliste($r['beskrivelse']),
                'aktiv' => Felt::tall($r['aktiv']) === 1,
            ];
        }

        return $ut;
    }

    /**
     * Dokumentet Bivrost Sentral leser (fase 2): appen, versjonen av definisjonen og alle rollene og tilgangene,
     * også de deaktiverte, med tekst på alle språk.
     *
     * @return array<string, mixed>
     */
    public function dokument(): array
    {
        return [
            'format' => 'bivrost-rolleregister',
            'versjon' => 1,
            'app' => ['id' => $this->app->app, 'navn' => $this->app->appnavn],
            'hash' => hash('sha256', json_encode($this->definisjoner(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            // Tomme tekstlister skal være JSON-objekter ({}), ikke lister ([]), etter skjema/rolleregister.schema.json.
            'roller' => array_map(static fn (array $r): array => array_merge($r, [
                'tekst' => $r['tekst'] === [] ? new \stdClass() : $r['tekst'],
                'beskrivelse' => $r['beskrivelse'] === [] ? new \stdClass() : $r['beskrivelse'],
            ]), $this->alle()),
        ];
    }

    /**
     * Om tekstene er like uansett rekkefølgen på språkene. MySQL lagrer JSON-objekter med nøklene sortert, MariaDB som de
     * ble skrevet, så en sammenligning med === ville meldt alt som endret på MySQL.
     *
     * @param array<string, string> $ny
     */
    private static function likeTekster(mixed $lagret, array $ny): bool
    {
        if (!is_array($lagret)) {
            return false;
        }
        ksort($lagret, SORT_STRING);
        ksort($ny, SORT_STRING);

        return $lagret === $ny;
    }

    /** @return array<string, string> */
    private static function tekstliste(mixed $json): array
    {
        $t = json_decode(is_string($json) ? $json : '{}', true);
        $ut = [];
        foreach (is_array($t) ? $t : [] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $ut[$k] = $v;
            }
        }

        return $ut;
    }
}
