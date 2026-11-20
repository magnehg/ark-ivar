<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Installasjon;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Innlogging\LokalBrukere;
use Bivrost\Kjerne\Innlogging\Passordhash;
use Bivrost\Kjerne\Innlogging\Passordregler;
use Bivrost\Kjerne\Innlogging\Totp;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * Installasjon i nettleseren for webhotell uten kommandolinje (DRF-01, BIV-DRF-02, L-035).
 *
 *  1. Installasjonskoden ligger i drift/installasjonskode.txt og må leses med FTP eller filbehandleren (den som
 *     installerer, viser at hen har tilgang til filene). Den sendes i hodet X-Bivrost-Installasjonskode på hvert kall,
 *     så installasjonen trenger ingen økt og ingen CSRF-nøkkel. Høyst 10 feil per IP-adresse på 15 minutter, telt i en
 *     fil (databasen finnes ikke ennå). Uten riktig kode gis ingen opplysninger utover «ikke installert».
 *  2. Databasen: tilkoblingen prøves, og installasjonen stopper med en liste hvis databasen har tabeller med
 *     installasjonens prefikser (bivrost_ og appens). .env skrives atomisk med 0640 og nye nøkler.
 *  3. Den første driftsbrukeren: passord (NIST SP 800-63B) og TOTP, som må bekreftes med en kode før noe lagres.
 *  4. Fullfør: migreringene for Kjerne og appen, brukeren, gjenopprettingskodene, eiermarkøren (bivrost_installasjon)
 *     og låsefilen drift/installert.json. Feiler noe, slettes bare tabellene denne kjøringen laget (aldri andres).
 * Etterpå svarer alle installasjonsrutene 404. Installasjonen regnes som ferdig også uten låsefilen når databasen har
 * eiermarkøren.
 *
 * @phpstan-type Migreringsfn callable(Db): void
 */
final class Installasjon
{
    private const MAKS_FEIL = 10;
    private const VINDU = 900;

    /** @param callable(Db): void $migrer Kjører migreringene for alle pakkene. */
    public function __construct(
        private readonly AppKonfig $app,
        private readonly ClockInterface $klokke,
        private $migrer,
    ) {
    }

    public function lasfil(): string
    {
        return $this->app->drift . '/installert.json';
    }

    private function kodefil(): string
    {
        return $this->app->drift . '/installasjonskode.txt';
    }

    private function ventefil(): string
    {
        return $this->app->drift . '/installasjon-ventende.json';
    }

    /** @return list<string> */
    public function prefikser(): array
    {
        return array_merge(['bivrost_'], $this->app->tabellprefikser);
    }

    public function installert(?Db $db): bool
    {
        if (is_file($this->lasfil())) {
            return true;
        }
        if ($db === null) {
            return false;
        }
        try {
            return $db->harTabell('bivrost_installasjon') && Felt::tall($db->verdi('SELECT COUNT(*) FROM bivrost_installasjon')) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Lager installasjonskoden når den mangler (0600). */
    public function sikreKode(): void
    {
        if (!is_dir($this->app->drift) && !@mkdir($this->app->drift, 0750, true) && !is_dir($this->app->drift)) {
            throw new BivrostFeil('bivrost.feil.installasjon.drift');
        }
        if (is_file($this->kodefil())) {
            return;
        }
        $kode = Tilfeldig::lesbarKode(20);
        $gammel = umask(0077);
        try {
            $tekst = "# Installasjonskoden for {$this->app->appnavn} (Bivrost). Skriv den inn i nettleseren.\n"
                . "# Filen slettes når installasjonen er ferdig.\n$kode\n";
            @file_put_contents($this->kodefil(), $tekst, LOCK_EX);
        } finally {
            umask($gammel);
        }
    }

    private function kode(): ?string
    {
        if (!is_file($this->kodefil())) {
            return null;
        }
        foreach (Felt::ellers(file($this->kodefil(), FILE_IGNORE_NEW_LINES), []) as $l) {
            $l = trim($l);
            if ($l !== '' && !str_starts_with($l, '#')) {
                return Tilfeldig::normaliserKode($l);
            }
        }

        return null;
    }

    /** Kontrollerer koden i hodet, med grensen per IP i en fil. Kaster 401 eller 429. */
    public function krevKode(Foresporsel $f): void
    {
        $fil = $this->app->drift . '/installasjon-forsok.json';
        $h = @fopen($fil, 'c+');
        if ($h === false) {
            throw new BivrostFeil('bivrost.feil.installasjon.drift');
        }
        try {
            flock($h, LOCK_EX);
            $j = json_decode((string) stream_get_contents($h), true);
            $naa = $this->klokke->now()->getTimestamp();
            $ip = hash('sha256', $f->ip);
            $liste = [];
            foreach (is_array($j) ? $j : [] as $k => $tider) {
                if (is_string($k) && is_array($tider)) {
                    $liste[$k] = array_values(array_filter($tider, static fn ($t): bool => is_int($t) && $t > $naa - self::VINDU));
                }
            }
            if (count($liste[$ip] ?? []) >= self::MAKS_FEIL) {
                throw new HttpFeil(429, 'for_mange_forsok', 'bivrost.feil.innlogging.for_mange', ['sekunder' => self::VINDU], ['Retry-After' => (string) self::VINDU]);
            }
            $riktig = $this->kode();
            $gitt = Tilfeldig::normaliserKode((string) $f->hode('x-bivrost-installasjonskode'));
            if ($riktig === null || $gitt === '' || !hash_equals($riktig, $gitt)) {
                $liste[$ip][] = $naa;
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, json_encode($liste, JSON_THROW_ON_ERROR));
                throw new HttpFeil(401, 'installasjonskode', 'bivrost.feil.installasjon.kode');
            }
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /**
     * Forutsetningene på serveren (vises først når koden er godkjent).
     *
     * @return list<array{id: string, ok: bool, kreves: bool}>
     */
    public function forutsetninger(): array
    {
        $skriv = fn (string $m): bool => is_dir($m) ? is_writable($m) : is_writable(dirname($m));

        return [
            ['id' => 'php', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'kreves' => true],
            ['id' => 'pdo_mysql', 'ok' => extension_loaded('pdo_mysql'), 'kreves' => true],
            ['id' => 'sodium', 'ok' => function_exists('sodium_crypto_secretbox'), 'kreves' => true],
            ['id' => 'openssl', 'ok' => extension_loaded('openssl'), 'kreves' => true],
            ['id' => 'zip', 'ok' => class_exists(\ZipArchive::class), 'kreves' => true],
            ['id' => 'curl', 'ok' => function_exists('curl_init'), 'kreves' => true],
            ['id' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'kreves' => true],
            ['id' => 'zlib', 'ok' => function_exists('deflate_init'), 'kreves' => true],
            ['id' => 'intl', 'ok' => extension_loaded('intl'), 'kreves' => false],
            ['id' => 'argon2id', 'ok' => defined('PASSWORD_ARGON2ID'), 'kreves' => false],
            ['id' => 'skriv_drift', 'ok' => $skriv($this->app->drift), 'kreves' => true],
            ['id' => 'skriv_env', 'ok' => is_file($this->app->envFil) ? is_writable($this->app->envFil) : is_writable(dirname($this->app->envFil)), 'kreves' => true],
            ['id' => 'drift_utenfor_webrot', 'ok' => !$this->underWebrot($this->app->drift), 'kreves' => true],
            ['id' => 'env_utenfor_webrot', 'ok' => !$this->underWebrot(dirname($this->app->envFil)), 'kreves' => true],
        ];
    }

    private function underWebrot(string $mappe): bool
    {
        if ($this->app->webrot === null) {
            return false;
        }
        $m = Felt::ellers(realpath($mappe), $mappe) . '/';
        $w = Felt::ellers(realpath($this->app->webrot), $this->app->webrot) . '/';

        return str_starts_with($m, $w);
    }

    /**
     * Tabellene i databasen med installasjonens prefikser (kollisjon).
     *
     * @return list<string>
     */
    public function kollisjoner(Db $db): array
    {
        $ut = [];
        foreach ($db->alle('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME') as $r) {
            foreach ($this->prefikser() as $p) {
                if (str_starts_with(Felt::tekst($r['t']), $p)) {
                    $ut[] = Felt::tekst($r['t']);
                    break;
                }
            }
        }

        return $ut;
    }

    /**
     * Steg 2: prøver databasen og skriver .env. $adresse er nettstedets adresse (https://…), som Bivrost bruker i
     * stedet for Host-hodet når den lager lenker til seg selv.
     *
     * @param array<string, mixed> $d
     * @return array{env: array<string, string>}
     */
    public function database(array $d, Env $eksisterende): array
    {
        $tekst = static function (string $k, int $maks, bool $kreves = true) use ($d): string {
            $v = $d[$k] ?? ($kreves ? null : '');
            if (!is_string($v) || ($kreves && $v === '') || strlen($v) > $maks || Env::verdiFeil($v) !== null) {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => $k]);
            }

            return $v;
        };
        $vert = $tekst('vert', 255);
        $port = $d['port'] ?? 3306;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'port']);
        }
        $navn = $tekst('navn', 64);
        $bruker = $tekst('bruker', 80);
        $passord = $tekst('passord', 200, false);
        $adresse = rtrim($tekst('adresse', 255), '/');
        $p = parse_url($adresse);
        if (
            !is_array($p) || !isset($p['host']) || isset($p['path']) && $p['path'] !== '' || isset($p['query'])
            || !(($p['scheme'] ?? '') === 'https' || (($p['scheme'] ?? '') === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true)))
        ) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.installasjon.adresse');
        }
        try {
            $db = Db::kobleTil(['vert' => $vert, 'port' => $port, 'navn' => $navn, 'bruker' => $bruker, 'passord' => $passord]);
        } catch (BivrostFeil) {
            throw new HttpFeil(422, 'database', 'bivrost.feil.installasjon.database');
        }
        $k = $this->kollisjoner($db);
        if ($k !== []) {
            throw new HttpFeil(409, 'tabeller_finnes', 'bivrost.feil.installasjon.tabeller_finnes', ['tabeller' => implode(', ', array_slice($k, 0, 30))]);
        }
        $env = array_merge($eksisterende->alle(), [
            'DB_VERT' => $vert, 'DB_PORT' => (string) $port, 'DB_NAVN' => $navn, 'DB_BRUKER' => $bruker, 'DB_PASSORD' => $passord,
            'BIVROST_ADRESSE' => $adresse,
            'BIVROST_KRYPTERINGSNOKKEL' => $eksisterende->hent('BIVROST_KRYPTERINGSNOKKEL') ?? Hemmelighetsboks::nyNokkel(),
            'BIVROST_HMAC_NOKKEL' => $eksisterende->hent('BIVROST_HMAC_NOKKEL') ?? Tilfeldig::token(32),
        ]);
        Env::skrivFil($this->app->envFil, $env, "Innstillinger og hemmeligheter for {$this->app->appnavn} (Bivrost). Aldri i git eller på nettet.", true);

        return ['env' => $env];
    }

    /**
     * Steg 3: kontrollerer brukeren og passordet og lager TOTP-hemmeligheten. Ingenting lagres i databasen ennå.
     *
     * @param array<string, mixed> $d
     * @return array{otpauth: string, hemmelighet: string}
     */
    public function bruker(array $d, Env $env, Totp $totp): array
    {
        $s = static function (string $k, int $maks, bool $kreves) use ($d): ?string {
            $v = $d[$k] ?? null;
            if ($v === null && !$kreves) {
                return null;
            }
            if (!is_string($v) || ($kreves && trim($v) === '') || mb_strlen($v, 'UTF-8') > $maks || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => $k]);
            }

            return $v;
        };
        $brukernavn = LokalBrukere::normaliser((string) $s('brukernavn', 64, true));
        if ($brukernavn === null) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.innlogging.brukernavn');
        }
        $navn = trim((string) $s('navn', 200, true));
        $tittel = $s('tittel', 200, false);
        $epost = $s('epost', 254, false);
        if ($epost !== null && $epost !== '' && filter_var($epost, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'epost']);
        }
        $passord = $d['passord'] ?? null;
        if (!is_string($passord)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'passord']);
        }
        $feil = Passordregler::feil($passord, [$brukernavn, $navn, $this->app->appnavn, $this->app->app]);
        if ($feil !== null) {
            throw new HttpFeil(422, 'passord_svakt', $feil);
        }
        $hemmelighet = $totp->nyHemmelighet();
        $boks = Hemmelighetsboks::fraBase64($env->krev('BIVROST_KRYPTERINGSNOKKEL'));
        $venter = [
            'brukernavn' => $brukernavn, 'navn' => $navn, 'tittel' => $tittel !== '' ? $tittel : null, 'epost' => $epost !== '' ? $epost : null,
            'passord_hash' => Passordhash::hash($passord),
            'totp' => $boks->krypter($hemmelighet, 'installasjon'),
            'til' => $this->klokke->now()->getTimestamp() + 900,
        ];
        $gammel = umask(0077);
        try {
            file_put_contents($this->ventefil(), json_encode($venter, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
        } finally {
            umask($gammel);
        }

        return ['otpauth' => $totp->adresse($hemmelighet, $this->app->appnavn, $brukernavn), 'hemmelighet' => $hemmelighet];
    }

    /**
     * Steg 4: kontrollerer TOTP-koden og fullfører. Gir brukerens ID og gjenopprettingskodene (vises én gang).
     *
     * @return array{bruker_id: string, gjenopprettingskoder: list<string>}
     */
    public function fullfor(string $kode, Db $db, Env $env, Totp $totp): array
    {
        $j = is_file($this->ventefil()) ? json_decode((string) file_get_contents($this->ventefil()), true) : null;
        if (
            !is_array($j) || !is_int($j['til'] ?? null) || $j['til'] < $this->klokke->now()->getTimestamp()
            || !is_string($j['totp'] ?? null) || !is_string($j['brukernavn'] ?? null) || !is_string($j['navn'] ?? null) || !is_string($j['passord_hash'] ?? null)
        ) {
            throw new HttpFeil(409, 'installasjon_steg', 'bivrost.feil.installasjon.steg');
        }
        $boks = Hemmelighetsboks::fraBase64($env->krev('BIVROST_KRYPTERINGSNOKKEL'));
        $hemmelighet = $boks->dekrypter($j['totp'], 'installasjon');
        $steg = $totp->kontroller($hemmelighet, $kode, null);
        if ($steg === null) {
            throw new HttpFeil(422, 'totp', 'bivrost.feil.innlogging.totp');
        }
        $kollisjoner = $this->kollisjoner($db);
        if ($kollisjoner !== []) {
            throw new HttpFeil(409, 'tabeller_finnes', 'bivrost.feil.installasjon.tabeller_finnes', ['tabeller' => implode(', ', array_slice($kollisjoner, 0, 30))]);
        }
        try {
            ($this->migrer)($db);
            $brukere = new LokalBrukere($db, $boks);
            $id = $brukere->opprett(
                $j['brukernavn'],
                $j['navn'],
                is_string($j['tittel'] ?? null) ? $j['tittel'] : null,
                is_string($j['epost'] ?? null) ? $j['epost'] : null,
                $j['passord_hash'],
                $hemmelighet,
                $steg
            );
            $koder = $brukere->nyeGjenopprettingskoder($id);
            $db->utfor('INSERT INTO bivrost_installasjon (id, app, laget, kjerne) VALUES (?, ?, ?, ?)', [Tilfeldig::uuid(), $this->app->app, Db::tid(), 'bivrost-kjerne']);
        } catch (\Throwable $e) {
            // Bare tabellene denne kjøringen laget: de hadde installasjonens prefikser og fantes ikke før (kollisjonene var tomme).
            foreach ($this->kollisjoner($db) as $t) {
                try {
                    $db->pdo()->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
                } catch (\Throwable) {
                    // Fortsetter med de andre.
                }
            }
            throw $e;
        }
        $gammel = umask(0027);
        try {
            file_put_contents($this->lasfil(), json_encode(['installert' => gmdate('c'), 'app' => $this->app->app], JSON_THROW_ON_ERROR), LOCK_EX);
        } finally {
            umask($gammel);
        }
        @unlink($this->ventefil());
        @unlink($this->kodefil());
        @unlink($this->app->drift . '/installasjon-forsok.json');

        return ['bruker_id' => $id, 'gjenopprettingskoder' => $koder];
    }
}
