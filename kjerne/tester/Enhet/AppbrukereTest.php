<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Logg\Aktor;
use Bivrost\Kjerne\Okt\Innlogget;
use PHPUnit\Framework\TestCase;

/** Ark-Ivars tillegg: en innlogging med appens egne brukere (metoden «app») kan ligge i økten. */
final class AppbrukereTest extends TestCase
{
    public function testInnloggingMedMetodenAppOverleverOkten(): void
    {
        $innlogget = new Innlogget(Aktor::bruker('b-1', 'Kari Student', 'Student'), ['urn:bivrost:app:arkivar:rolle:student'], 'app', 1000, null, 3, 'kari@hogskolen.example');
        $lest = Innlogget::fraListe($innlogget->tilListe());
        self::assertNotNull($lest);
        self::assertSame('app', $lest->metode);
        self::assertSame(3, $lest->generasjon);
        self::assertSame('kari@hogskolen.example', $lest->epost);
    }

    public function testUkjentMetodeAvvises(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Innlogget(Aktor::bruker('b-1', 'Kari Student'), [], 'annet', 1000);
    }
}
