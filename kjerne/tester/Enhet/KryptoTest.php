<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Innlogging\Totp;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Oppdatering\Signatur;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use PHPUnit\Framework\TestCase;

final class KryptoTest extends TestCase
{
    public function testHemmelighetsboksTurRetur(): void
    {
        $b = Hemmelighetsboks::fraBase64(Hemmelighetsboks::nyNokkel());
        $k = $b->krypter('JBSWY3DPEHPK3PXP', 'totp:1');
        self::assertStringStartsWith('v1:', $k);
        self::assertNotSame($k, $b->krypter('JBSWY3DPEHPK3PXP', 'totp:1'), 'Ny nonce hver gang.');
        self::assertSame('JBSWY3DPEHPK3PXP', $b->dekrypter($k, 'totp:1'));
    }

    public function testFeilFormaalOgEndretVerdiAvvises(): void
    {
        $b = Hemmelighetsboks::fraBase64(Hemmelighetsboks::nyNokkel());
        $k = $b->krypter('x', 'totp:1');
        try {
            $b->dekrypter($k, 'totp:2');
            self::fail('Et annet formål skal ikke kunne åpne verdien.');
        } catch (BivrostFeil $e) {
            self::assertSame('bivrost.feil.krypto.apne', $e->tekstnokkel);
        }
        $data = (string) base64_decode(substr($k, 3), true);
        $data[30] = chr(ord($data[30]) ^ 1);
        $this->expectException(BivrostFeil::class);
        $b->dekrypter('v1:' . base64_encode($data), 'totp:1');
    }

    public function testNokkelskriptetVirkerUtenAvhengighetene(): void
    {
        // bin/lag-signeringsnokkel.php skal virke rett etter en klone, uten vendor/ (f.eks. i GitHub Codespaces).
        $mappe = sys_get_temp_dir() . '/bivrost-nokkel-' . bin2hex(random_bytes(6));
        mkdir("$mappe/bin", 0700, true);
        mkdir("$mappe/src/Oppdatering", 0700, true);
        copy(__DIR__ . '/../../bin/lag-signeringsnokkel.php', "$mappe/bin/lag-signeringsnokkel.php");
        copy(__DIR__ . '/../../src/Oppdatering/Signatur.php', "$mappe/src/Oppdatering/Signatur.php");
        $ut = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$mappe/bin/lag-signeringsnokkel.php") . ' 2>&1', $ut, $kode);
        foreach (["$mappe/bin/lag-signeringsnokkel.php", "$mappe/src/Oppdatering/Signatur.php"] as $f) {
            unlink($f);
        }
        rmdir("$mappe/src/Oppdatering");
        rmdir("$mappe/src");
        rmdir("$mappe/bin");
        rmdir($mappe);
        self::assertSame(0, $kode, implode("\n", $ut));
        $offentlig = $ut[1] ?? '';
        $hemmelig = $ut[4] ?? '';
        self::assertTrue(Signatur::gyldigNokkel($offentlig));
        $sha = hash('sha256', 'pakke');
        $sig = Signatur::signer($hemmelig, 'bivrost-kjerne', 'bivrost-kjerne-v0.1.0', $sha);
        self::assertTrue(Signatur::stemmer($offentlig, 'bivrost-kjerne', 'bivrost-kjerne-v0.1.0', $sha, $sig));
        self::assertFalse(Signatur::stemmer($offentlig, 'bivrost-kjerne', 'bivrost-kjerne-v0.1.1', $sha, $sig));
    }

    public function testUgyldigNokkel(): void
    {
        $this->expectException(BivrostFeil::class);
        Hemmelighetsboks::fraBase64(base64_encode('for kort'));
    }

    public function testTilfeldig(): void
    {
        self::assertTrue(Tilfeldig::erUuid(Tilfeldig::uuid()));
        self::assertFalse(Tilfeldig::erUuid('00000000-0000-0000-0000-000000000000'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', Tilfeldig::token());
        $k = Tilfeldig::lesbarKode(16);
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/', $k);
        self::assertSame('01123', Tilfeldig::normaliserKode('o-i l 23'));
    }

    public function testTotpEtterRfc6238MedVernMotGjenbruk(): void
    {
        $klokke = new FastKlokke('2026-11-13 10:00:00');
        $t = new Totp($klokke);
        // Testvektoren i RFC 6238 (SHA-1, T = 59): 94287082 med 8 sifre gir 287082 med 6.
        self::assertSame('287082', $t->kode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
        $h = $t->nyHemmelighet();
        $kode = $t->kode($h);
        $steg = $t->kontroller($h, $kode, null);
        self::assertSame(intdiv($klokke->now()->getTimestamp(), 30), $steg);
        self::assertNull($t->kontroller($h, $kode, $steg), 'Samme tidssteg kan ikke brukes to ganger.');
        $klokke->frem(30);
        self::assertNotNull($t->kontroller($h, $kode, null), 'Ett steg bak godtas (klokkeavvik).');
        $klokke->frem(60);
        self::assertNull($t->kontroller($h, $kode, null), 'Tre steg bak avvises.');
        self::assertNull($t->kontroller($h, 'abc123', null));
        self::assertStringStartsWith('otpauth://totp/', $t->adresse($h, 'Gerð', 'drift'));
    }

    public function testSignaturEd25519(): void
    {
        $par = Signatur::nyttNokkelpar();
        $sha = hash('sha256', 'pakke');
        $sig = Signatur::signer($par['hemmelig'], 'bivrost-kjerne', 'bivrost-kjerne-v0.1.0', $sha);
        self::assertTrue(Signatur::stemmer($par['offentlig'], 'bivrost-kjerne', 'bivrost-kjerne-v0.1.0', $sha, $sig));
        self::assertFalse(Signatur::stemmer($par['offentlig'], 'gerd', 'bivrost-kjerne-v0.1.0', $sha, $sig), 'Pakkenavnet er med i signaturen.');
        self::assertFalse(Signatur::stemmer($par['offentlig'], 'bivrost-kjerne', 'bivrost-kjerne-v0.1.1', $sha, $sig));
        self::assertFalse(Signatur::stemmer($par['offentlig'], 'bivrost-kjerne', 'bivrost-kjerne-v0.1.0', hash('sha256', 'annen'), $sig));
        self::assertFalse(Signatur::stemmer('ikke base64!', 'bivrost-kjerne', 'bivrost-kjerne-v0.1.0', $sha, $sig));
        self::assertTrue(Signatur::gyldigNokkel($par['offentlig']));
        self::assertFalse(Signatur::gyldigNokkel(base64_encode('kort')));
    }
}
