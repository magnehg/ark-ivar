<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use PHPUnit\Framework\TestCase;

/** OpenAPI 3.1 (BIV-STD-02): beskrivelsen er gyldig og har alle rutene Kjerne svarer på. */
final class OpenApiTest extends TestCase
{
    /**
     * Grunnleggende kontroll (selve valideringen mot OpenAPI 3.1-skjemaet gjøres med Redocly i CI): versjon, alle
     * operasjoner har svar og operationId, og alle $ref peker på noe som finnes.
     */
    public function testBeskrivelsenHengerSammen(): void
    {
        $tekst = (string) file_get_contents(__DIR__ . '/../../openapi.json');
        $dok = json_decode($tekst, true);
        self::assertIsArray($dok);
        self::assertSame('3.1.0', $dok['openapi']);
        self::assertIsArray($dok['paths']);
        $ider = [];
        foreach ($dok['paths'] as $metoder) {
            self::assertIsArray($metoder);
            foreach ($metoder as $o) {
                self::assertIsArray($o);
                self::assertNotEmpty($o['responses']);
                self::assertIsString($o['operationId']);
                $ider[] = $o['operationId'];
            }
        }
        self::assertCount(count($ider), array_unique($ider));
        preg_match_all('/"\$ref": "([^"]+)"/', $tekst, $m);
        foreach (array_unique($m[1]) as $ref) {
            if (str_starts_with($ref, '#/')) {
                $x = $dok;
                foreach (explode('/', substr($ref, 2)) as $del) {
                    self::assertIsArray($x);
                    self::assertArrayHasKey($del, $x, $ref);
                    $x = $x[$del];
                }
            } else {
                self::assertFileExists(__DIR__ . '/../../' . $ref);
                self::assertIsArray(json_decode((string) file_get_contents(__DIR__ . '/../../' . $ref), true));
            }
        }
    }

    public function testAlleRuteneErBeskrevet(): void
    {
        $dok = json_decode((string) file_get_contents(__DIR__ . '/../../openapi.json'), true);
        self::assertIsArray($dok);
        self::assertIsArray($dok['paths']);
        $beskrevet = [];
        foreach ($dok['paths'] as $sti => $metoder) {
            foreach (is_array($metoder) ? array_keys($metoder) : [] as $m) {
                $beskrevet[] = strtoupper((string) $m) . ' ' . $sti;
            }
        }
        $kode = (string) file_get_contents(__DIR__ . '/../../src/Kjerne.php');
        preg_match_all("/legg\\('([A-Z]+)', '([^']+)'/", $kode, $m, PREG_SET_ORDER);
        $ruter = array_map(static fn (array $x): string => $x[1] . ' ' . $x[2], $m);
        $ruter = array_merge($ruter, [
            'GET /bivrost/helse', 'GET /bivrost/ressurser/{sti}', 'GET /bivrost/api/installasjon', 'POST /bivrost/api/installasjon/database',
            'POST /bivrost/api/installasjon/bruker', 'POST /bivrost/api/installasjon/fullfor', 'POST /bivrost/oidc/utlogging',
            'GET /bivrost/api/system/oppdatering/status', 'POST /bivrost/api/system/oppdatering/kjor',
        ]);
        sort($ruter);
        sort($beskrevet);
        self::assertSame($ruter, $beskrevet);
    }
}
