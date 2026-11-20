<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Ruter;
use Bivrost\Kjerne\Http\Sikkerhetshoder;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Logg\Hendelse;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testJsonKroppMedStrengTypekontroll(): void
    {
        $f = new Foresporsel('POST', '/x', ['content-type' => 'application/json'], kropp: '{"navn": ["ikke", "tekst"], "a": "b"}');
        self::assertSame('b', $f->tekst('a'));
        try {
            $f->tekst('navn');
            self::fail('En liste der en tekst ventes, skal avvises.');
        } catch (HttpFeil $e) {
            self::assertSame(422, $e->status);
        }
        $this->expectException(HttpFeil::class);
        (new Foresporsel('POST', '/x', ['content-type' => 'text/plain'], kropp: '{}'))->json();
    }

    public function testBearer(): void
    {
        self::assertSame('abc.def-_', (new Foresporsel('GET', '/', ['authorization' => 'Bearer abc.def-_']))->bearer());
        self::assertNull((new Foresporsel('GET', '/', ['authorization' => 'Basic abc']))->bearer());
        self::assertNull((new Foresporsel('GET', '/', ['authorization' => "Bearer a b"]))->bearer());
    }

    public function testRuterMedVerdierOg405(): void
    {
        $r = new Ruter();
        $r->legg('GET', '/a/{navn}', static fn (Foresporsel $f, array $v): Svar => new Svar(200, $v['navn'] ?? ''));
        self::assertSame('db-1.sql', $r->handter(new Foresporsel('GET', '/a/db-1.sql'))?->kropp);
        self::assertSame(405, $r->handter(new Foresporsel('POST', '/a/x'))?->status);
        self::assertNull($r->handter(new Foresporsel('GET', '/a/x/y')));
        self::assertNull($r->handter(new Foresporsel('GET', '/a/..%2f')));
    }

    public function testSikkerhetshoderOgKapsler(): void
    {
        $s = Sikkerhetshoder::sett(new Svar(), true);
        self::assertStringContainsString("frame-ancestors 'none'", (string) $s->hode('Content-Security-Policy'));
        self::assertStringContainsString("script-src 'self'", (string) $s->hode('Content-Security-Policy'));
        self::assertSame('nosniff', $s->hode('X-Content-Type-Options'));
        self::assertNotNull($s->hode('Strict-Transport-Security'));
        self::assertNull(Sikkerhetshoder::sett(new Svar(), false)->hode('Strict-Transport-Security'));
        $s->settKapsel('__Host-bivrost', 'abc', -1, true);
        self::assertSame(['__Host-bivrost=abc; Path=/; HttpOnly; SameSite=Lax; Secure'], $s->kapsler());
        $this->expectException(\InvalidArgumentException::class);
        $s->settHode('X-Test', "a\r\nSet-Cookie: x=y");
    }

    public function testHendelseTurRetur(): void
    {
        $h = new Hendelse(
            '2b1d7e8a-1c2d-4e5f-8a9b-0c1d2e3f4a5b',
            '2026-11-13T10:00:00.000Z',
            ['kode' => 'revisjon', 'tekst' => 'Revisjonslogg'],
            ['urn' => 'urn:bivrost:hendelse:tilgang:endret', 'tekst' => 'Tilgang endret'],
            ['type' => 'bruker', 'type_tekst' => 'Bruker', 'id' => 'sub-1', 'navn' => 'Kari Nordmann', 'tittel' => 'Arkivleder'],
            ['app' => 'gerd', 'type' => 'bruker', 'type_tekst' => 'Bruker', 'id' => 'sub-2', 'navn' => 'Ola'],
            ['aktiv' => ['kode' => true, 'tekst' => 'Aktiv']],
            ['aktiv' => ['kode' => false, 'tekst' => 'Ikke aktiv']],
            [['id' => 'gerd', 'navn' => 'Gerð']],
            ['kode' => 'nett', 'tekst' => 'Nettleser'],
            '2b1d7e8a-1c2d-4e5f-8a9b-0c1d2e3f4a5c',
            ['x' => 1]
        );
        $j = json_decode((string) json_encode($h->tilListe()), true);
        self::assertIsArray($j);
        /** @var array<string, mixed> $j */
        self::assertEquals($h, Hendelse::fraListe($j));
        $this->expectException(\InvalidArgumentException::class);
        new Hendelse('x', '2026-11-13 10:00', ['kode' => 'revisjon', 'tekst' => ''], ['urn' => 'ikke urn', 'tekst' => ''], $h->hvem, null, [], [], [], $h->kilde, 'x');
    }

    public function testLoopbackKreverKlientServerOgVert(): void
    {
        self::assertTrue((new Foresporsel('GET', '/', ip: '127.0.0.1', vert: 'localhost:8080', serverIp: '127.0.0.1'))->loopback());
        self::assertTrue((new Foresporsel('GET', '/', ip: '::1', vert: '[::1]', serverIp: '::1'))->loopback());
        self::assertFalse((new Foresporsel('GET', '/', ip: '10.0.0.1', vert: 'localhost', serverIp: '127.0.0.1'))->loopback());
        self::assertFalse((new Foresporsel('GET', '/', ip: '127.0.0.1', vert: 'gerd.example.no', serverIp: '127.0.0.1'))->loopback());
        self::assertFalse((new Foresporsel('GET', '/', ip: '127.0.0.1', vert: 'localhost', serverIp: '192.168.1.2'))->loopback());
    }
}
