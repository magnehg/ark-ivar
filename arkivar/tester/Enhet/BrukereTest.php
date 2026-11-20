<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Enhet;

use ArkIvar\Brukere\Brukere;
use ArkIvar\Brukere\Domener;
use ArkIvar\Brukere\Roller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrukereTest extends TestCase
{
    public function testEpostadressenNormaliseresTilSmaBokstaver(): void
    {
        self::assertSame('kari@stud.hogskolen.example', Brukere::normaliserEpost('  Kari@Stud.Hogskolen.Example '));
        self::assertSame('stud.hogskolen.example', Brukere::domene('kari@stud.hogskolen.example'));
    }

    /** @return list<array{string}> */
    public static function ugyldigeAdresser(): array
    {
        return [[''], ['kari'], ['kari@'], ['@hogskolen.example'], ['kari@hogskolen'], ['kari @hogskolen.example'], ["kari@hogskolen.example\nBcc: x@y.example"],
            ['kári@hogskolen.example'], [str_repeat('a', 250) . '@x.example']];
    }

    #[DataProvider('ugyldigeAdresser')]
    public function testUgyldigeAdresserAvvises(string $adresse): void
    {
        self::assertNull(Brukere::normaliserEpost($adresse));
    }

    public function testDomenetNormaliseres(): void
    {
        self::assertSame('hogskolen.example', Domener::normaliser(' @Hogskolen.Example. '));
        self::assertSame('stud.hogskolen.example', Domener::normaliser('stud.hogskolen.example'));
        self::assertNull(Domener::normaliser('example'), 'Minst to ledd.');
        self::assertNull(Domener::normaliser('hog skolen.example'));
        self::assertNull(Domener::normaliser('-hogskolen.example'));
        self::assertNull(Domener::normaliser('høgskolen.example'));
    }

    public function testRolleneErEtHierarki(): void
    {
        self::assertSame(['urn:bivrost:app:arkivar:rolle:student'], Roller::urner('student'));
        self::assertSame(
            ['urn:bivrost:app:arkivar:rolle:student', 'urn:bivrost:app:arkivar:rolle:laerer', 'urn:bivrost:app:arkivar:rolle:administrator'],
            Roller::urner('administrator'),
        );
        self::assertSame([], Roller::urner('ukjent'));
        self::assertFalse(Roller::gyldig('drift'));
    }
}
