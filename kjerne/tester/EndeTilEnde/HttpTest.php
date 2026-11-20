<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\EndeTilEnde;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Innlogging\Totp;
use Bivrost\Kjerne\Klokke;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\TestDb;
use Bivrost\Kjerne\Tester\Stotte\Tjener;

/**
 * Over ekte HTTP mot PHPs webserver med en liten app (tester/Stotte/eksempelapp.php): forespørselen fra de globale
 * variablene, informasjonskapsler, CSRF-hodet, sikkerhetshodene og svaret slik nettleseren får det.
 */
final class HttpTest extends DbTestCase
{
    private string $rot = '';
    private ?Tjener $tjener = null;
    private string $kapsler = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rot = Mappe::ny();
        Env::skrivFil("$this->rot/.env", TestDb::env($this->dbNavn) + ['BIVROST_TILLAT_HTTP_LOKALT' => '1', 'GITHUB_API_URL' => 'http://127.0.0.1:9',
            'BIVROST_ADRESSE' => 'http://localhost', 'BIVROST_KRYPTERINGSNOKKEL' => \Bivrost\Kjerne\Krypto\Hemmelighetsboks::nyNokkel(), 'BIVROST_HMAC_NOKKEL' => 'hmac']);
        $this->tjener = new Tjener(__DIR__ . '/../Stotte/eksempelapp.php', ['EKSEMPEL_ROT' => $this->rot]);
        $this->kapsler = "$this->rot/kapsler.txt";
    }

    protected function tearDown(): void
    {
        $this->tjener?->stopp();
        Mappe::slett($this->rot);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $kropp
     * @param list<string> $hoder
     * @return array{status: int, hoder: string, json: array<string, mixed>}
     */
    private function kall(string $metode, string $sti, ?array $kropp = null, array $hoder = []): array
    {
        $h = curl_init('http://localhost:' . $this->tjener?->port . $sti);
        self::assertNotFalse($h);
        $hodeListe = $hoder;
        if ($kropp !== null) {
            $hodeListe[] = 'Content-Type: application/json';
        }
        foreach (
            [CURLOPT_CUSTOMREQUEST => $metode, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->kapsler,
            CURLOPT_COOKIEFILE => $this->kapsler, CURLOPT_HTTPHEADER => $hodeListe, CURLOPT_NOPROXY => '*'] as $valg => $verdi
        ) {
            curl_setopt($h, $valg, $verdi);
        }
        if ($kropp !== null) {
            curl_setopt($h, CURLOPT_POSTFIELDS, (string) json_encode($kropp));
        }
        $svar = (string) curl_exec($h);
        $storrelse = curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        $j = json_decode(substr($svar, $storrelse), true);
        $ut = [];
        foreach (is_array($j) ? $j : [] as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return ['status' => $status, 'hoder' => substr($svar, 0, $storrelse), 'json' => $ut];
    }

    public function testInstallasjonOgInnloggingOverHttp(): void
    {
        $r = $this->kall('GET', '/bivrost/api/installasjon');
        self::assertSame(200, $r['status']);
        self::assertSame(['installert' => false, 'kode_kreves' => true], $r['json']);
        self::assertStringContainsString("Content-Security-Policy: default-src 'self'", $r['hoder']);
        self::assertStringContainsString('X-Bivrost-Korrelasjon: ', $r['hoder']);
        $kode = '';
        foreach (Felt::ellers(file("$this->rot/drift/installasjonskode.txt", FILE_IGNORE_NEW_LINES), []) as $l) {
            $kode = str_starts_with($l, '#') ? $kode : $l;
        }
        $kh = ["X-Bivrost-Installasjonskode: $kode"];
        $r = $this->kall('POST', '/bivrost/api/installasjon/bruker', ['brukernavn' => 'drift', 'navn' => 'Drift Driftesen', 'passord' => 'lang og god passfrase'], $kh);
        self::assertSame(200, $r['status'], (string) json_encode($r['json']));
        $totp = new Totp(new Klokke());
        $r = $this->kall('POST', '/bivrost/api/installasjon/fullfor', ['kode' => $totp->kode(Felt::tekst($r['json']['hemmelighet']))], $kh);
        self::assertSame(200, $r['status'], (string) json_encode($r['json']));

        $r = $this->kall('GET', '/hei', null, ['Accept: application/json']);
        self::assertSame(401, $r['status']);
        self::assertStringContainsString('Content-Type: application/problem+json', $r['hoder']);
        self::assertStringNotContainsString('X-Powered-By', $r['hoder'], 'PHP-versjonen sendes ikke ut (ASVS 14.3.3).');
        $r = $this->kall('GET', '/hei');
        self::assertStringContainsString('Content-Type: text/html', $r['hoder'], 'Nettleseren får en feilside.');
        $r = $this->kall('GET', '/bivrost/api/okt');
        $csrf = Felt::tekst($r['json']['csrf']);
        self::assertMatchesRegularExpression('/Set-Cookie: bivrost=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax/', $r['hoder']);
        $r = $this->kall('POST', '/bivrost/api/innlogging/lokal', ['brukernavn' => 'drift', 'passord' => 'lang og god passfrase'], ["X-CSRF: $csrf", 'Origin: http://localhost:' . $this->tjener?->port]);
        self::assertSame(200, $r['status'], (string) json_encode($r['json']));
        $csrf = Felt::tekst($r['json']['csrf']);
        // Koden fra installasjonen er brukt (samme tidssteg kan ikke brukes igjen); neste steg er innenfor vinduet.
        $r = $this->kall('POST', '/bivrost/api/innlogging/lokal/totp', ['kode' => $totp->kode($this->hemmelighet(), time() + 30)], ["X-CSRF: $csrf"]);
        self::assertSame(200, $r['status'], (string) json_encode($r['json']));
        $r = $this->kall('GET', '/hei');
        self::assertSame(['hei' => 'Drift Driftesen'], $r['json']);
        $r = $this->kall('GET', '/finnes-ikke');
        self::assertSame(404, $r['status']);
    }

    private function hemmelighet(): string
    {
        $env = Env::fraFil("$this->rot/.env");
        $b = $this->db->en('SELECT id, totp_hemmelighet FROM bivrost_lokal_bruker');
        self::assertNotNull($b);

        return \Bivrost\Kjerne\Krypto\Hemmelighetsboks::fraBase64($env->krev('BIVROST_KRYPTERINGSNOKKEL'))->dekrypter(Felt::tekst($b['totp_hemmelighet']), 'totp:' . Felt::tekst($b['id']));
    }
}
