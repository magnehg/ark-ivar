<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Sprak\IcuFormatter;
use Bivrost\Kjerne\Sprak\Sprakkontroll;
use Bivrost\Kjerne\Sprak\Tekster;
use Bivrost\Kjerne\Sprak\Xliff;
use Bivrost\Kjerne\Tester\Stotte\Mappe;
use Bivrost\Kjerne\Tester\Stotte\Skjema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Språkfilene, språkpakkene, ICU-formateringen og XLIFF (BIV-SPR-01–08). */
final class TeksterTest extends TestCase
{
    public function testSprakfileneErIOrden(): void
    {
        $rot = dirname(__DIR__, 2);
        self::assertSame([], Sprakkontroll::kjor($rot . '/i18n', 'bivrost.', [$rot . '/src', dirname($rot) . '/grensesnitt/src']));
    }

    public function testKontrollenFinnerFeil(): void
    {
        $m = Mappe::ny();
        try {
            Mappe::skriv("$m/i18n/nb/bivrost-x.json", (string) json_encode(['bivrost.a' => 'Hei {navn}', 'bivrost.b' => 'Ubrukt', 'feil.prefiks' => 'x', 'bivrost.c' => '{n, plural, one {#}']));
            Mappe::skriv("$m/i18n/nn/bivrost-x.json", (string) json_encode(['bivrost.a' => 'Hei {namn}']));
            Mappe::skriv("$m/src/A.php", "<?php\n\$x = 'bivrost.a'; \$y = 'bivrost.mangler';\n\$z = 'Dette er en setning til brukeren.';\nthrow new \\RuntimeException('Dette er for utviklere.');\n");
            Mappe::skriv("$m/src/B.tsx", "export const B = () => <p>Hei verden</p>;\n");
            $funn = implode("\n", Sprakkontroll::kjor("$m/i18n", 'bivrost.', ["$m/src"]));
            foreach (
                ['nn/bivrost-x.json: mangler bivrost.b', 'nn/bivrost-x.json: bivrost.a har andre plassholdere', 'feil.prefiks mangler prefikset', 'bivrost.c er ikke gyldig ICU',
                'nøkkelen bivrost.mangler finnes ikke', 'bivrost.b brukes ikke', 'en/bivrost-x.json: filen mangler', 'bokstavelig tekst «Dette er en setning', 'bokstavelig tekst «Hei verden» i TSX'] as $ventet
            ) {
                self::assertStringContainsString($ventet, $funn);
            }
            self::assertStringNotContainsString('for utviklere', $funn);
        } finally {
            Mappe::slett($m);
        }
    }

    public function testFormateringOgReservesprak(): void
    {
        $t = new Tekster();
        self::assertSame('For mange forsøk. Prøv igjen om 30 sekunder.', $t->tekst('bivrost.feil.innlogging.for_mange', ['sekunder' => 30]));
        self::assertSame('Too many attempts. Try again in 30 seconds.', $t->tekst('bivrost.feil.innlogging.for_mange', ['sekunder' => 30], 'en'));
        self::assertSame('ukjent.nokkel', $t->tekst('ukjent.nokkel'));
        self::assertSame('Arkivar (Gerð)', $t->tekst('bivrost.rolle.visning', ['rolle' => 'Arkivar', 'app' => 'Gerð'], 'se'));
        self::assertSame(['nb', 'nn', 'en'], $t->sprak());
    }

    public function testSprakpakkeUtenKodeendring(): void
    {
        $m = Mappe::ny();
        try {
            Mappe::skriv("$m/pakker/se/sprakpakke.json", (string) json_encode(['format' => 'bivrost-sprakpakke', 'formatversjon' => 1, 'kode' => 'se', 'navn' => ['se' => 'Davvisámegiella', 'nb' => 'Nordsamisk'], 'versjon' => '0.1.0']));
            Mappe::skriv("$m/pakker/se/bivrost-kjerne.json", (string) json_encode(['bivrost.feil.tilgang.innlogging' => 'Don fertet logget sisa.']));
            Mappe::skriv("$m/pakker/xx/sprakpakke.json", '{"format": "annet"}');
            self::assertSame([], Skjema::feil('sprakpakke', json_decode((string) file_get_contents("$m/pakker/se/sprakpakke.json"), true)));
            $t = new Tekster([dirname(__DIR__, 2) . '/i18n', "$m/pakker"]);
            self::assertSame(['nb', 'nn', 'en', 'se'], $t->sprak());
            self::assertSame('Don fertet logget sisa.', $t->tekst('bivrost.feil.tilgang.innlogging', [], 'se'));
            self::assertSame('se', $t->kilde('bivrost.feil.tilgang.innlogging', 'se'));
            self::assertSame('nb', $t->kilde('bivrost.feil.intern', 'se'), 'Mangler teksten, brukes hovedspråket (med lang-merking).');
        } finally {
            Mappe::slett($m);
        }
    }

    /** @return iterable<string, array{string, array<string, scalar|null>, string}> */
    public static function icu(): iterable
    {
        yield 'enkel' => ['Hei {navn}!', ['navn' => 'Kari'], 'nb'];
        yield 'flertall en' => ['{n, plural, =0 {ingen filer} one {# fil} other {# filer}}', ['n' => 1], 'nb'];
        yield 'flertall mange' => ['{n, plural, =0 {ingen filer} one {# fil} other {# filer}}', ['n' => 1234], 'nb'];
        yield 'flertall null' => ['{n, plural, =0 {ingen filer} one {# fil} other {# filer}}', ['n' => 0], 'nb'];
        yield 'select' => ['{kjonn, select, a {Alfa} other {Annet}} og {n, number}', ['kjonn' => 'a', 'n' => 42], 'nb'];
        yield 'apostrof' => ["Det er ''{x}'' og '{'klammer'}'", ['x' => 'y'], 'nb'];
        yield 'engelsk tall' => ['{n, plural, one {# file} other {# files}}', ['n' => 1500], 'en'];
    }

    /** @param array<string, scalar|null> $v */
    #[DataProvider('icu')]
    public function testIcuErstatningenGirSammeSomIntl(string $monster, array $v, string $sprak): void
    {
        self::assertTrue(IcuFormatter::gyldig($monster));
        $egen = IcuFormatter::formater($monster, $v, $sprak);
        if (class_exists(\MessageFormatter::class)) {
            $intl = (new Tekster())->formater($monster, $v, $sprak);
            self::assertSame(str_replace("\u{00A0}", ' ', $intl), $egen);
        }
        self::assertSame($egen, (new Tekster([], 'nb', false))->formater($monster, $v, $sprak));
        self::assertFalse(IcuFormatter::gyldig('{n, plural, one {x}'));
        self::assertFalse(IcuFormatter::gyldig('tekst }'));
    }

    /**
     * Feilen fra GitHub sier hva statuskoden betyr, likt med og uten intl. MessageFormatter brukes direkte, fordi
     * Tekster faller tilbake på IcuFormatter i stillhet når intl ikke godtar mønsteret.
     */
    public function testGithubfeilenForklarerStatuskodenMedOgUtenIntl(): void
    {
        foreach (['nb', 'nn', 'en'] as $sprak) {
            $monster = (new Tekster())->katalog($sprak)['bivrost.feil.oppdatering.github'] ?? null;
            self::assertIsString($monster);
            foreach ([401, 403, 404, 500] as $status) {
                $egen = IcuFormatter::formater($monster, ['status' => $status], $sprak);
                self::assertStringContainsString("HTTP $status)", $egen);
                self::assertSame($status === 500, str_ends_with($egen, "(HTTP $status)."), 'Bare 401, 403 og 404 har en forklaring.');
                if (class_exists(\MessageFormatter::class)) {
                    $intl = (new \MessageFormatter($sprak === 'en' ? 'en' : $sprak . '_NO', $monster))->format(['status' => $status]);
                    self::assertSame($egen, $intl, "intl godtar mønsteret ($sprak, $status).");
                }
            }
        }
    }

    public function testXliffTurRetur(): void
    {
        $m = Mappe::ny();
        try {
            $rot = dirname(__DIR__, 2) . '/i18n';
            $xml = Xliff::eksporter($rot, 'nb', 'nn');
            self::assertStringContainsString('urn:oasis:names:tc:xliff:document:2.0', $xml);
            self::assertStringContainsString('trgLang="nn"', $xml);
            self::assertSame(count((array) json_decode((string) file_get_contents("$rot/nn/bivrost-kjerne.json"), true)), Xliff::skriv("$m/i18n", $xml));
            self::assertJsonFileEqualsJsonFile("$rot/nn/bivrost-kjerne.json", "$m/i18n/nn/bivrost-kjerne.json");
            // XXE: eksterne entiteter lastes ikke.
            $ondsinnet = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="nb" trgLang="se"><file id="a"><unit id="k"><segment><source>x</source><target>&e;</target></segment></unit></file></xliff>';
            $r = Xliff::importer($ondsinnet);
            self::assertStringNotContainsString('root:', (string) json_encode($r));
        } finally {
            Mappe::slett($m);
        }
    }
}
