<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Database;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Konfig\Env;

/**
 * Databaselaget (PLT-01, PLT-05, SIK-02): PDO mot MySQL 8 eller MariaDB 10.6+, bare parameteriserte spørringer, ekte
 * forberedte setninger (ikke emulerte), streng SQL-modus, utf8mb4 og UTC. Alt annet i Bivrost går gjennom denne
 * klassen, så en annen database kan legges til senere.
 *
 * Oppsettet står i .env: DB_VERT, DB_PORT (3306), DB_NAVN, DB_BRUKER, DB_PASSORD, og eventuelt DB_SOCKET.
 */
final class Db
{
    private int $dybde = 0;

    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    /**
     * @param array{vert?: string, port?: int, navn: string, bruker: string, passord: string, socket?: string} $o
     */
    public static function kobleTil(array $o): self
    {
        $dsn = isset($o['socket']) && $o['socket'] !== ''
            ? 'mysql:unix_socket=' . $o['socket'] . ';dbname=' . $o['navn'] . ';charset=utf8mb4'
            : 'mysql:host=' . ($o['vert'] ?? '127.0.0.1') . ';port=' . ($o['port'] ?? 3306) . ';dbname=' . $o['navn'] . ';charset=utf8mb4';
        try {
            $pdo = new \PDO($dsn, $o['bruker'], $o['passord'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            // Meldingen fra PDO kan ha vertsnavn og brukernavn. Den videreføres bare som forrige feil (til loggen på
            // serveren), ikke i teksten til brukeren.
            throw new BivrostFeil('bivrost.feil.db.tilkobling', [], $e);
        }
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
            . "SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return new self($pdo);
    }

    public static function fraEnv(Env $env): self
    {
        $o = [
            'navn' => $env->krev('DB_NAVN'),
            'bruker' => $env->krev('DB_BRUKER'),
            'passord' => $env->hent('DB_PASSORD', '') ?? '',
            'vert' => $env->hent('DB_VERT', '127.0.0.1') ?? '127.0.0.1',
            'port' => $env->heltall('DB_PORT', 3306),
        ];
        $socket = $env->hent('DB_SOCKET');
        if ($socket !== null && $socket !== '') {
            $o['socket'] = $socket;
        }

        return self::kobleTil($o);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * @param list<scalar|null> $p
     * @return list<array<string, mixed>>
     */
    public function alle(string $sql, array $p = []): array
    {
        $st = $this->kjor($sql, $p);
        /** @var list<array<string, mixed>> $rader */
        $rader = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $rader;
    }

    /**
     * @param list<scalar|null> $p
     * @return array<string, mixed>|null
     */
    public function en(string $sql, array $p = []): ?array
    {
        $st = $this->kjor($sql, $p);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        /** @var array<string, mixed>|false $r */
        return $r === false ? null : $r;
    }

    /** @param list<scalar|null> $p */
    public function verdi(string $sql, array $p = []): mixed
    {
        $st = $this->kjor($sql, $p);
        $v = $st->fetchColumn();
        $st->closeCursor();

        return $v === false ? null : $v;
    }

    /**
     * Kjører en setning og gir antall berørte rader.
     *
     * @param list<scalar|null> $p
     */
    public function utfor(string $sql, array $p = []): int
    {
        $st = $this->kjor($sql, $p);
        $n = $st->rowCount();
        $st->closeCursor();

        return $n;
    }

    /** @param list<scalar|null> $p */
    private function kjor(string $sql, array $p): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($p as $i => $v) {
            $st->bindValue($i + 1, $v, match (true) {
                is_int($v) => \PDO::PARAM_INT,
                is_bool($v) => \PDO::PARAM_BOOL,
                $v === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
        $st->execute();

        return $st;
    }

    /**
     * Kjører $fn i en transaksjon og gir verdien tilbake. Feil ruller tilbake og kastes videre. Nestede kall blir en
     * del av den ytterste transaksjonen.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaksjon(callable $fn): mixed
    {
        if ($this->dybde > 0) {
            $this->dybde++;
            try {
                return $fn($this);
            } finally {
                $this->dybde--;
            }
        }
        $this->pdo->beginTransaction();
        $this->dybde = 1;
        try {
            $r = $fn($this);
            $this->pdo->commit();

            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->dybde = 0;
        }
    }

    public function harTabell(string $tabell): bool
    {
        return Felt::tall($this->verdi(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tabell],
        )) > 0;
    }

    public function databasenavn(): string
    {
        $v = $this->verdi('SELECT DATABASE()');

        return is_string($v) ? $v : '';
    }

    /** Tidspunktet nå i UTC med mikrosekunder, til DATETIME(6)-kolonner. */
    public static function tid(?\DateTimeImmutable $t = null): string
    {
        return ($t ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** Navngitt lås for hele databaseserveren (GET_LOCK), med navnet bundet til databasen. */
    public function las(string $navn, int $sekunder): bool
    {
        return Felt::tall($this->verdi('SELECT GET_LOCK(?, ?)', [$this->lasnavn($navn), $sekunder])) === 1;
    }

    public function slippLas(string $navn): void
    {
        try {
            $this->verdi('SELECT RELEASE_LOCK(?)', [$this->lasnavn($navn)]);
        } catch (\Throwable) {
            // Låsen slippes uansett når tilkoblingen lukkes.
        }
    }

    private function lasnavn(string $navn): string
    {
        // MySQL tillater høyst 64 tegn: «bivrost:<navn, høyst 15>:<sha1 av databasenavnet>».
        return 'bivrost:' . substr($navn, 0, 15) . ':' . sha1($this->databasenavn());
    }
}
