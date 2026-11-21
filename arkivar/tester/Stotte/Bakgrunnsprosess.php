<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Stotte;

use ArkIvar\Arkiv\Dokumentmaler;
use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Mapper;
use ArkIvar\Arkiv\Oppgavesett;
use ArkIvar\Arkiv\Ovingsarkiv;
use ArkIvar\Arkiv\Postmottak;
use ArkIvar\Arkiv\Register;
use ArkIvar\Arkiv\Skriving;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\TestDb;

/**
 * En operasjon i øvingsarkivet kjørt i en egen PHP-prosess (bakgrunn.php), med sin egen databaseforbindelse, slik
 * to forespørsler samtidig er det i drift. Testene av samtidighet starter prosessene mens de selv holder en lås, og
 * ser i databasen at prosessen står og venter på låsen før de slipper den: da er rekkefølgen bestemt, uten pauser.
 *
 * Prosessen skriver to linjer med JSON: først nummeret på databaseforbindelsen (før operasjonen starter), så
 * resultatet. Klassen har begge sidene: start() og resten brukes av testen, utfor() av bakgrunn.php.
 *
 * @phpstan-type Resultat array{ok: bool, verdi: mixed, feil: string, status: int, kode: string, melding: string}
 */
final class Bakgrunnsprosess
{
    /** Hvor lenge testen venter på en linje fra prosessen, eller på at den skal stå i kø, før den gir opp. */
    private const TALMODIGHET = 20.0;

    /** Operasjonene bakgrunn.php kan utføre. */
    public const FOR_BRUKER = 'for_bruker';
    public const JOURNALPOST = 'journalpost';
    public const OPPRETT_MAPPE = 'opprett_mappe';
    public const NULLSTILL = 'nullstill';

    /** Når oversikten over transaksjonene i InnoDB sist ble lest (se ventTilDenVenter). */
    private static float $lestTransaksjoner = 0.0;

    private int $forbindelse = 0;

    /** @var Resultat|null */
    private ?array $resultat = null;

    /**
     * @param resource $prosess
     * @param resource $ut Det prosessen skriver.
     */
    private function __construct(private $prosess, private $ut)
    {
    }

    /**
     * Starter operasjonen i en ny prosess og venter til den har koblet til databasen.
     *
     * @param list<mixed> $argumenter Argumentene til operasjonen (se utfor()).
     */
    public static function start(string $dbNavn, \DateTimeImmutable $tid, string $operasjon, array $argumenter): self
    {
        $inn = json_encode(['db' => $dbNavn, 'tid' => $tid->format('Y-m-d H:i:s'), 'operasjon' => $operasjon, 'argumenter' => $argumenter], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ror = [];
        // Feilmeldinger fra PHP skal også komme i røret (display_errors), så en prosess som stopper, forklarer hvorfor.
        $kommando = [PHP_BINARY, '-d', 'display_errors=1', __DIR__ . '/bakgrunn.php', $inn];
        $prosess = proc_open($kommando, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $ror);
        if (!is_resource($prosess) || !isset($ror[1]) || !is_resource($ror[1])) {
            throw new \RuntimeException('Kunne ikke starte bakgrunnsprosessen.');
        }
        $ny = new self($prosess, $ror[1]);
        $ny->forbindelse = Felt::tall($ny->linje()['forbindelse'] ?? 0);
        if ($ny->forbindelse === 0) {
            throw new \RuntimeException('Bakgrunnsprosessen ga ikke nummeret på databaseforbindelsen.');
        }

        return $ny;
    }

    public function __destruct()
    {
        fclose($this->ut);
        if (proc_get_status($this->prosess)['running']) {
            proc_terminate($this->prosess, 9);
        }
        proc_close($this->prosess);
    }

    /** Sann når prosessen har skrevet noe (resultatet) eller er avsluttet. */
    private function harSkrevet(float $sekunder): bool
    {
        $les = [$this->ut];
        $skriv = null;
        $unntak = null;
        $hele = (int) floor($sekunder);

        return stream_select($les, $skriv, $unntak, $hele, (int) (($sekunder - $hele) * 1_000_000)) === 1;
    }

    /**
     * Neste linje fra prosessen, som JSON.
     *
     * @return array<string, mixed>
     */
    private function linje(): array
    {
        if (!$this->harSkrevet(self::TALMODIGHET)) {
            throw new \RuntimeException('Bakgrunnsprosessen svarte ikke i tide.');
        }
        $linje = (string) fgets($this->ut);
        $json = json_decode($linje, true);
        if (!is_array($json)) {
            // Ikke JSON: en feilmelding fra PHP. Resten av det prosessen skrev, tas med.
            throw new \RuntimeException('Uventet svar fra bakgrunnsprosessen: ' . $linje . stream_get_contents($this->ut));
        }
        $ut = [];
        foreach ($json as $nokkel => $verdi) {
            $ut[(string) $nokkel] = $verdi;
        }

        return $ut;
    }

    /**
     * Venter til prosessen står i kø bak en lås i databasen: en radlås (Register::laas og annet som låser rader)
     * eller en navngitt lås (Db::las). $db må være en annen forbindelse enn den som holder låsen i en transaksjon,
     * og brukeren må kunne se andre forbindelser (PROCESS). Gir usann hvis prosessen ble ferdig uten å vente, eller
     * ikke kom så langt i tide.
     */
    public function ventTilDenVenter(Db $db): bool
    {
        $frist = microtime(true) + self::TALMODIGHET;
        while (microtime(true) < $frist) {
            if ($db->verdi("SELECT 1 FROM information_schema.PROCESSLIST WHERE ID = ? AND STATE = 'User lock'", [$this->forbindelse]) !== null) {
                return true;
            }
            // InnoDB fornyer bare oversikten over transaksjonene når ingen har lest den på 0,1 sekund. Leses den
            // oftere, står den stille, og en ny venter kommer aldri til syne.
            if (microtime(true) - self::$lestTransaksjoner > 0.12) {
                $venter = $db->verdi("SELECT 1 FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'", [$this->forbindelse]) !== null;
                self::$lestTransaksjoner = microtime(true);
                if ($venter) {
                    return true;
                }
            }
            // Pausen mellom to oppslag er ikke synkroniseringen: den avbrytes når prosessen svarer, og da venter den ikke.
            if ($this->harSkrevet(0.005)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Resultatet av operasjonen. Venter til prosessen er ferdig.
     *
     * @return Resultat
     */
    public function resultat(): array
    {
        if ($this->resultat === null) {
            $linje = $this->linje();
            $this->resultat = [
                'ok' => ($linje['ok'] ?? false) === true,
                'verdi' => $linje['verdi'] ?? null,
                'feil' => Felt::tekst($linje['feil'] ?? null),
                'status' => Felt::tall($linje['status'] ?? 0),
                'kode' => Felt::tekst($linje['kode'] ?? null),
                'melding' => Felt::tekst($linje['melding'] ?? null),
            ];
        }

        return $this->resultat;
    }

    /** Resultatet som tekst, til meldingen i en påstand som feiler. */
    public function forklaring(): string
    {
        return json_encode($this->resultat(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Prosessens side (kalt fra bakgrunn.php): kobler til testdatabasen som TestDb, skriver nummeret på
     * forbindelsen, utfører operasjonen med klassene i appen og skriver resultatet.
     */
    public static function utfor(string $inn): void
    {
        $skriv = static function (array $linje): void {
            echo json_encode($linje, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
            flush();
        };
        try {
            $oppdrag = self::tabell(json_decode($inn, true, 32, JSON_THROW_ON_ERROR));
            $db = TestDb::kobleTil(Felt::tekst($oppdrag['db'] ?? null));
            $klokke = new FastKlokke(Felt::tekst($oppdrag['tid'] ?? null));
            $grunndata = new Grunndata();
            $oppgaver = new Oppgavesett();
            $register = new Register($db, $klokke, $grunndata);
            $a = array_values(is_array($oppdrag['argumenter'] ?? null) ? $oppdrag['argumenter'] : []);
            $skriv(['forbindelse' => Felt::tall($db->verdi('SELECT CONNECTION_ID()'))]);
            $verdi = match (Felt::tekst($oppdrag['operasjon'] ?? null)) {
                self::FOR_BRUKER => (new Ovingsarkiv($db, $klokke, $grunndata, $register, $oppgaver))->forBruker(Felt::tekst($a[0] ?? null)),
                self::JOURNALPOST => (new Postmottak($db, $klokke, $grunndata, $oppgaver, $register, new Skriving($db, $klokke, $grunndata, new Dokumentmaler(), $register)))
                    ->journalpost(Felt::tekst($a[0] ?? null), Felt::tekst($a[1] ?? null), self::tabell($a[2] ?? null))['journalpost']['id'] ?? null,
                self::OPPRETT_MAPPE => (new Mapper($db, $klokke, $grunndata, $register))->opprett(Felt::tekst($a[0] ?? null), self::tabell($a[1] ?? null)),
                self::NULLSTILL => (new Ovingsarkiv($db, $klokke, $grunndata, $register, $oppgaver))->nullstill(Felt::tekst($a[0] ?? null)),
                default => throw new \InvalidArgumentException('Ukjent operasjon.'),
            };
            $skriv(['ok' => true, 'verdi' => $verdi]);
        } catch (HttpFeil $e) {
            $skriv(['ok' => false, 'feil' => $e::class, 'status' => $e->status, 'kode' => $e->kode, 'melding' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $skriv(['ok' => false, 'feil' => $e::class, 'melding' => $e->getMessage()]);
        }
    }

    /**
     * En matrise med tekstnøkler (et JSON-objekt).
     *
     * @return array<string, mixed>
     */
    private static function tabell(mixed $verdi): array
    {
        $ut = [];
        foreach (is_array($verdi) ? $verdi : [] as $nokkel => $felt) {
            $ut[(string) $nokkel] = $felt;
        }

        return $ut;
    }
}
