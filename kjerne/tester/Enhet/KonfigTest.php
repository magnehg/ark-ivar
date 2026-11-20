<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KonfigTest extends TestCase
{
    private string $rot = '';

    protected function setUp(): void
    {
        $this->rot = Mappe::ny();
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->rot);
    }

    public function testGyldigKonfigurasjonMedStandardverdier(): void
    {
        $k = AppKonfig::fraListe(['app' => 'gerd', 'appnavn' => 'Gerð', 'rot' => $this->rot, 'roller' => [
            ['navn' => 'arkivar', 'tekst' => ['nb' => 'Arkivar', 'en' => 'Archivist']],
            ['navn' => 'leser', 'tekst' => 'Leser'],
        ]]);
        self::assertSame($this->rot . '/drift', $k->drift);
        self::assertSame('nb', $k->hovedsprak);
        self::assertSame(30, $k->oktInaktivMinutter);
        self::assertSame(365, $k->loggOppbevaringDager);
        self::assertSame(['nb' => 'Leser'], $k->roller[1]['tekst']);
        self::assertSame([], $k->loggtabeller);
        $k = AppKonfig::fraListe(['app' => 'gerd', 'appnavn' => 'Gerð', 'rot' => $this->rot, 'tabellprefikser' => ['gerd_'], 'logg' => ['tabeller' => ['gerd_logg']]]);
        self::assertSame(['gerd_logg'], $k->loggtabeller);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function ugyldige(): iterable
    {
        yield 'app med store bokstaver' => [['app' => 'Gerd']];
        yield 'app bivrost' => [['app' => 'bivrost']];
        yield 'rolle to ganger' => [['roller' => [['navn' => 'a1', 'tekst' => 'A'], ['navn' => 'a1', 'tekst' => 'B']]]];
        yield 'rolle uten bokmål' => [['roller' => [['navn' => 'a1', 'tekst' => ['en' => 'A']]]]];
        yield 'rolle med ugyldig navn' => [['roller' => [['navn' => 'A B', 'tekst' => 'A']]]];
        yield 'prefiks bivrost_' => [['tabellprefikser' => ['bivrost_']]];
        yield 'pakke i bivrost/' => [['pakke' => ['repo' => 'a/b', 'tagprefiks' => 'gerd-v', 'mappe' => 'bivrost/gerd/']]];
        yield 'separasjon med maks lik antall' => [['separasjon' => [['navn' => 's', 'urner' => ['urn:bivrost:tilgang:drift'], 'maks' => 1]]]];
        yield 'ukjent språk' => [['hovedsprak' => 'se']];
        yield 'loggtabell uten appens prefiks' => [['tabellprefikser' => ['gerd_'], 'logg' => ['tabeller' => ['bivrost_logg']]]];
        yield 'loggtabell med ugyldig navn' => [['tabellprefikser' => ['gerd_'], 'logg' => ['tabeller' => ['gerd_logg`; DROP']]]];
        yield 'loggtabeller som ikke er en liste' => [['tabellprefikser' => ['gerd_'], 'logg' => ['tabeller' => 'gerd_logg']]];
    }

    /** @param array<string, mixed> $over */
    #[DataProvider('ugyldige')]
    public function testUgyldigKonfigurasjonGirFeil(array $over): void
    {
        $this->expectException(BivrostFeil::class);
        AppKonfig::fraListe($over + ['app' => 'gerd', 'appnavn' => 'Gerð', 'rot' => $this->rot]);
    }

    public function testDriftsmappenKanIkkeLiggeUnderWebroten(): void
    {
        mkdir($this->rot . '/public');
        $this->expectException(BivrostFeil::class);
        AppKonfig::fraListe(['app' => 'gerd', 'appnavn' => 'Gerð', 'rot' => $this->rot, 'webrot' => $this->rot . '/public', 'drift' => $this->rot . '/public/drift']);
    }
}
