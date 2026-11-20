<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

use Bivrost\Kjerne\Database\Db;

/**
 * Egne testdatabaser med prefikset biv_test_ (aldri andre databaser). Lokalt via MariaDB-socketen som root;
 * i CI med BIVROST_TEST_DB_VERT, _PORT, _BRUKER og _PASSORD.
 */
final class TestDb
{
    /** @return array{vert?: string, port?: int, socket?: string, bruker: string, passord: string} */
    public static function oppsett(): array
    {
        $vert = getenv('BIVROST_TEST_DB_VERT');
        if (is_string($vert) && $vert !== '') {
            $port = getenv('BIVROST_TEST_DB_PORT');

            return [
                'vert' => $vert,
                'port' => is_string($port) && $port !== '' ? (int) $port : 3306,
                'bruker' => (string) getenv('BIVROST_TEST_DB_BRUKER'),
                'passord' => (string) getenv('BIVROST_TEST_DB_PASSORD'),
            ];
        }

        return ['socket' => '/run/mysqld/mysqld.sock', 'bruker' => 'root', 'passord' => ''];
    }

    private static function admin(): \PDO
    {
        $o = self::oppsett();
        $dsn = isset($o['socket']) ? 'mysql:unix_socket=' . $o['socket'] : 'mysql:host=' . ($o['vert'] ?? '127.0.0.1') . ';port=' . ($o['port'] ?? 3306);

        return new \PDO($dsn . ';charset=utf8mb4', $o['bruker'], $o['passord'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public static function tilgjengelig(): bool
    {
        try {
            self::admin();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Lager en tom database og gir navnet. */
    public static function ny(): string
    {
        $navn = 'biv_test_' . bin2hex(random_bytes(5));
        self::admin()->exec("CREATE DATABASE `$navn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        return $navn;
    }

    public static function kobleTil(string $navn): Db
    {
        $o = self::oppsett() + ['navn' => $navn];

        return Db::kobleTil($o);
    }

    /** @return array<string, string> DB-delen av .env for databasen. */
    public static function env(string $navn): array
    {
        $o = self::oppsett();
        $e = ['DB_NAVN' => $navn, 'DB_BRUKER' => $o['bruker'], 'DB_PASSORD' => $o['passord']];
        if (isset($o['socket'])) {
            $e['DB_SOCKET'] = $o['socket'];
        } else {
            $e['DB_VERT'] = $o['vert'] ?? '127.0.0.1';
            $e['DB_PORT'] = (string) ($o['port'] ?? 3306);
        }

        return $e;
    }

    public static function slett(string $navn): void
    {
        if (preg_match('/^biv_test_[0-9a-f]{10}$/', $navn) !== 1) {
            throw new \InvalidArgumentException('Sletter bare testdatabaser.');
        }
        self::admin()->exec("DROP DATABASE IF EXISTS `$navn`");
    }
}
