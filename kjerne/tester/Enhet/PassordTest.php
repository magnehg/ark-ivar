<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Innlogging\Passordhash;
use Bivrost\Kjerne\Innlogging\Passordregler;
use PHPUnit\Framework\TestCase;

final class PassordTest extends TestCase
{
    public function testReglerEtterNist(): void
    {
        self::assertSame('bivrost.feil.passord.kort', Passordregler::feil('kort ord'));
        self::assertSame('bivrost.feil.passord.langt', Passordregler::feil(str_repeat('abcdefgh ', 8)));
        self::assertSame('bivrost.feil.passord.vanlig', Passordregler::feil('Passord123456'));
        self::assertSame('bivrost.feil.passord.vanlig', Passordregler::feil('Sommer2026!!!'));
        self::assertSame('bivrost.feil.passord.vanlig', Passordregler::feil('1q2w3e4r5t6y'));
        self::assertSame('bivrost.feil.passord.gjentatt', Passordregler::feil('aaaaaaaaaaaaaaa'));
        self::assertSame('bivrost.feil.passord.rekke', Passordregler::feil('abcdefghijklm'));
        self::assertSame('bivrost.feil.passord.sammenheng', Passordregler::feil('kari.nordmann1', ['kari.nordmann']));
        self::assertSame('bivrost.feil.passord.tegn', Passordregler::feil("\xFF\xFE ugyldig utf8 her"));
        // Lange passfraser, mellomrom og alle tegn er lov; ingen komposisjonsregler.
        self::assertNull(Passordregler::feil('hest batteri stift korrekt'));
        self::assertNull(Passordregler::feil('blåbærsyltetøy på vaffel'));
    }

    public function testListenOverLekkedePassordErStor(): void
    {
        // 10 000 fra SecLists pluss norske (V2.1.7).
        $linjer = file(__DIR__ . '/../../ressurser/vanlige-passord.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($linjer);
        self::assertGreaterThan(10000, count($linjer));
        self::assertSame('bivrost.feil.passord.vanlig', Passordregler::feil('qwertyuiop12'));
    }

    public function testHashStemmerOgNormaliseres(): void
    {
        $h = Passordhash::hash('blåbærsyltetøy på vaffel');
        self::assertTrue(Passordhash::stemmer('blåbærsyltetøy på vaffel', $h));
        // NFD (dekomponert å) gir samme passord etter NFC-normalisering.
        self::assertTrue(Passordhash::stemmer("bla\u{030A}bærsyltetøy på vaffel", $h));
        self::assertFalse(Passordhash::stemmer('blåbærsyltetøy på vaffe1', $h));
        self::assertFalse(Passordhash::trengerNy($h));
        if (defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('$argon2id$', $h);
        }
    }

    public function testBcryptMedForhashBrukerHelePassordet(): void
    {
        $lang = str_repeat('ø', 40) . 'slutt-A';
        $lang2 = str_repeat('ø', 40) . 'slutt-B';
        $h = 'bivrost-bcrypt-sha384$' . password_hash(base64_encode(hash('sha384', $lang, true)), PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue(Passordhash::stemmer($lang, $h));
        self::assertFalse(Passordhash::stemmer($lang2, $h), 'Mer enn 72 byte skal telle.');
        self::assertTrue(Passordhash::trengerNy($h) || !defined('PASSWORD_ARGON2ID'));
        self::assertFalse(Passordhash::stemmer('hva som helst', Passordhash::attrapp()));
    }
}
