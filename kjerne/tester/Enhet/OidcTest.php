<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Oidc\OidcFeil;
use Bivrost\Kjerne\Oidc\OidcOppsett;
use Bivrost\Kjerne\Oidc\StandardOidcKlient;
use Bivrost\Kjerne\Tester\Stotte\FalskSentral;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use PHPUnit\Framework\TestCase;

/** OIDC-klienten mot en etterlignet Sentral: PKCE, state, nonce, signatur, utsteder, mottaker og back-channel logout. */
final class OidcTest extends TestCase
{
    private FastKlokke $klokke;
    private FalskSentral $sentral;
    private StandardOidcKlient $klient;

    protected function setUp(): void
    {
        $this->klokke = new FastKlokke();
        $this->sentral = new FalskSentral($this->klokke);
        $this->klient = new StandardOidcKlient(new OidcOppsett(FalskSentral::UTSTEDER, 'gerd', 'hemmelig', 'https://gerd.test/bivrost/innlogging/tilbake', 'gerd'), $this->klokke, $this->sentral);
    }

    /** @return array{0: array{state: string, nonce: string, verifikator: string, tid: int}, 1: array<string, string>} */
    private function start(): array
    {
        $s = $this->klient->start();
        parse_str((string) parse_url($s->adresse, PHP_URL_QUERY), $q);
        self::assertSame('S256', $q['code_challenge_method'] ?? null);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $s->tilstand['verifikator'], true)), '+/', '-_'), '='), $q['code_challenge'] ?? null);
        self::assertSame($s->tilstand['state'], $q['state'] ?? null);
        self::assertSame($s->tilstand['nonce'], $q['nonce'] ?? null);
        self::assertStringStartsWith(FalskSentral::UTSTEDER . '/autoriser?', $s->adresse);

        // Den etterlignede Sentral legger nonce i koden, så tokenendepunktet kan sette den i ID-tokenet.
        return [$s->tilstand, ['state' => $s->tilstand['state'], 'code' => 'kode:' . $s->tilstand['nonce']]];
    }

    public function testHelInnloggingGirIdentitetMedRollerForAppen(): void
    {
        [$t, $q] = $this->start();
        $i = $this->klient->fullfor($q, $t);
        self::assertSame($t['verifikator'], $this->sentral->sisteVerifikator, 'PKCE-verifikatoren sendes til tokenendepunktet.');
        self::assertSame('sub-kari', $i->sub);
        self::assertSame('sid-1', $i->sid);
        self::assertSame('Kari Nordmann', $i->navn);
        self::assertSame('Arkivleder', $i->tittel);
        self::assertTrue($i->epostBekreftet);
        self::assertSame(['urn:bivrost:app:gerd:rolle:arkivar', 'urn:bivrost:tilgang:drift'], $i->urner, 'Roller for andre apper tas ikke med.');
        self::assertSame('mose', $i->preferanser['tema']);
        self::assertSame('Europe/Oslo', $i->preferanser['timezone']);
        self::assertSame('tilgang-123', $i->tilgangstoken);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function ugyldigeToken(): iterable
    {
        yield 'feil utsteder' => [['iss' => 'https://angriper.test']];
        yield 'feil mottaker' => [['aud' => 'hugr']];
        yield 'flere mottakere uten azp' => [['aud' => ['gerd', 'hugr']]];
        yield 'feil nonce' => [['nonce' => 'annen']];
        yield 'utløpt' => [['exp' => 1]];
        yield 'uten sub' => [['sub' => '']];
    }

    /** @param array<string, mixed> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('ugyldigeToken')]
    public function testUgyldigeTokenAvvises(array $over): void
    {
        [$t, $q] = $this->start();
        $this->sentral->paastander = $over;
        $this->expectException(OidcFeil::class);
        $this->klient->fullfor($q, $t);
    }

    public function testFeilStateOgUtloptTilstandAvvises(): void
    {
        [$t, $q] = $this->start();
        try {
            $this->klient->fullfor(['state' => 'feil', 'code' => $q['code']], $t);
            self::fail('Feil state.');
        } catch (OidcFeil $e) {
            self::assertSame('bivrost.feil.oidc.tilstand', $e->tekstnokkel);
        }
        $this->klokke->frem(601);
        $this->expectException(OidcFeil::class);
        $this->klient->fullfor($q, $t);
    }

    public function testFeilSignaturOgAlgoritmeAvvises(): void
    {
        [$t, $q] = $this->start();
        $annen = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($annen);
        $p = '';
        openssl_pkey_export($annen, $p);
        self::assertIsString($p);
        $this->sentral->privat = $p;
        try {
            $this->klient->fullfor($q, $t);
            self::fail('Feil signatur.');
        } catch (OidcFeil) {
            self::assertGreaterThanOrEqual(1, $this->sentral->jwksHentet);
        }
        [$t, $q] = $this->start();
        $this->sentral->alg = 'HS256';
        $this->sentral->privat = 'delt-hemmelighet-som-angriper-kjenner-0123456789';
        $this->expectException(OidcFeil::class);
        $this->klient->fullfor($q, $t);
    }

    public function testUkjentNokkelIdHenterNoklenePaNytt(): void
    {
        [$t, $q] = $this->start();
        $this->klient->fullfor($q, $t);
        $for = $this->sentral->jwksHentet;
        $this->sentral->kid = 'n2';
        $this->sentral->jwk['kid'] = 'n2';
        [$t, $q] = $this->start();
        $this->klient->fullfor($q, $t);
        self::assertSame($for + 1, $this->sentral->jwksHentet);
    }

    public function testBackchannelLogout(): void
    {
        $n = $this->klokke->now()->getTimestamp();
        $gyldig = ['iss' => FalskSentral::UTSTEDER, 'aud' => 'gerd', 'iat' => $n, 'exp' => $n + 120, 'jti' => 'j1', 'sid' => 'sid-1', 'sub' => 'sub-kari',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass()]];
        $u = $this->klient->backchannel($this->sentral->signer($gyldig));
        self::assertSame(['sid' => 'sid-1', 'sub' => 'sub-kari', 'jti' => 'j1', 'utloper' => $n + 120], $u);
        foreach ([['nonce' => 'x'], ['events' => ['annen' => 1]], ['jti' => null], ['sid' => null, 'sub' => null]] as $feil) {
            try {
                $this->klient->backchannel($this->sentral->signer(array_filter(array_merge($gyldig, $feil), static fn ($v): bool => $v !== null)));
                self::fail((string) json_encode($feil));
            } catch (OidcFeil) {
                self::addToAssertionCount(1);
            }
        }
        self::assertStringStartsWith(FalskSentral::UTSTEDER . '/logg-ut?', (string) $this->klient->utloggingsadresse('https://gerd.test/'));
    }

    public function testOppsettKreverHttps(): void
    {
        $this->expectException(\Bivrost\Kjerne\Feil\BivrostFeil::class);
        new OidcOppsett('http://login.test', 'gerd', '', 'https://gerd.test/x', 'gerd');
    }
}
