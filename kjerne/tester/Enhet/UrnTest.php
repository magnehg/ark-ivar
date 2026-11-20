<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Tilgang\Urn;
use PHPUnit\Framework\TestCase;

final class UrnTest extends TestCase
{
    public function testLagerOgTolkerUrner(): void
    {
        self::assertSame('urn:bivrost:app:gerd:rolle:arkivar', Urn::rolle('gerd', 'arkivar'));
        self::assertSame(['slag' => 'rolle', 'app' => 'gerd', 'navn' => 'arkivar'], Urn::tolk('urn:bivrost:app:gerd:rolle:arkivar'));
        self::assertSame(['slag' => 'tilgang', 'app' => null, 'navn' => 'drift'], Urn::tolk(Urn::DRIFT));
        self::assertSame(['slag' => 'tilgang', 'app' => 'hugr', 'navn' => 'appadministrator'], Urn::tolk(Urn::appTilgang('hugr', 'appadministrator')));
    }

    public function testAvviserAltAnnet(): void
    {
        foreach (['', 'urn:bivrost:tilgang:', 'urn:bivrost:app:Gerd:rolle:x', 'urn:annet:tilgang:drift', 'urn:bivrost:tilgang:drift ', "urn:bivrost:tilgang:drift\n"] as $u) {
            self::assertNull(Urn::tolk($u), $u);
        }
        $this->expectException(\InvalidArgumentException::class);
        Urn::rolle('gerd', 'Arkivar');
    }

    public function testGjelderApp(): void
    {
        self::assertTrue(Urn::gjelderApp(Urn::DRIFT, 'gerd'));
        self::assertTrue(Urn::gjelderApp('urn:bivrost:app:gerd:rolle:leser', 'gerd'));
        self::assertFalse(Urn::gjelderApp('urn:bivrost:app:hugr:rolle:leser', 'gerd'));
        self::assertFalse(Urn::gjelderApp('roles', 'gerd'));
    }
}
