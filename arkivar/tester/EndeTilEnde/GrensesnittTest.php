<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\App;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Kjerne;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Tester\Stotte\DbTestCase;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use Bivrost\Kjerne\Tester\Stotte\Mappe;

/**
 * Siden grensesnittet starter fra: den vises også før installasjonen (så installasjonen kan gjøres i nettleseren),
 * og hvert svar har en egen engangsverdi for stilene i CSP-hodet og i siden.
 */
final class GrensesnittTest extends DbTestCase
{
    private string $rot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rot = Mappe::ny();
        Mappe::skriv($this->rot . '/index.html', '<meta name="arkivar-stil-nonce" content="ARKIVAR-STIL-NONCE"><div id="root"></div>');
        // Ingen database i .env: appen er ikke installert.
        Env::skrivFil($this->rot . '/.env', ['GITHUB_API_URL' => 'http://127.0.0.1:9']);
    }

    protected function tearDown(): void
    {
        Mappe::slett($this->rot);
        parent::tearDown();
    }

    /** @param array<string, string> $hoder */
    private function hent(string $sti, array $hoder = [], string $metode = 'GET'): Svar
    {
        $kjerne = new Kjerne(AppKonfig::fraListe(['rot' => $this->rot, 'webrot' => $this->rot . '/public'] + App::konfig()), new FastKlokke());
        $app = new App($kjerne, new FastKlokke(), null, null, $this->rot . '/index.html');

        return $kjerne->handter(new Foresporsel($metode, $sti, $hoder, [], [], '', '10.0.0.1', true, 'localhost'), $app->ruter(...));
    }

    public function testSidenVisesForInstallasjonenMenIkkeApiet(): void
    {
        $svar = $this->hent('/');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertStringContainsString('<div id="root">', $svar->kropp);
        self::assertSame(200, $this->hent('/en/annen/side')->status, 'Alle sidene gir grensesnittet, som viser installasjonen.');

        $api = $this->hent('/api/oppsett', ['accept' => 'application/json']);
        self::assertSame(503, $api->status);
        self::assertStringContainsString('ikke_installert', $api->kropp);
        $okt = $this->hent('/bivrost/api/okt', ['accept' => 'application/json']);
        self::assertSame(503, $okt->status, 'Kjernens ruter svarer som før.');
        self::assertSame(503, $this->hent('/', [], 'POST')->status, 'Bare GET går til appen før installasjonen.');
    }

    public function testHvertSvarHarSinEgenEngangsverdiForStilene(): void
    {
        $forste = $this->hent('/');
        $andre = $this->hent('/');
        self::assertStringNotContainsString('ARKIVAR-STIL-NONCE', $forste->kropp);
        self::assertSame(1, preg_match('/content="([A-Za-z0-9_-]{16,})"/', $forste->kropp, $m));
        $csp = (string) $forste->hode('Content-Security-Policy');
        self::assertStringContainsString("style-src 'self' 'nonce-{$m[1]}'", $csp, 'Verdien i siden er den samme som i CSP-hodet.');
        self::assertStringContainsString("script-src 'self';", $csp, 'Skript er fortsatt bare filer fra egen server.');
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertNotSame($forste->kropp, $andre->kropp, 'Ny verdi for hvert svar.');
        self::assertSame('no-store', $forste->hode('Cache-Control'));
    }
}
