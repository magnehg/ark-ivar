<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Oppgavesett;
use ArkIvar\Arkiv\Ovingsarkiv;
use ArkIvar\Arkiv\Register;
use ArkIvar\Tester\Stotte\AppTestCase;
use Bivrost\Kjerne\Krypto\Tilfeldig;

/**
 * Utgaven av innholdet: arkivet husker et fingeravtrykk av begge datafilene (mappene i sirevag.json og
 * postmottaket i oppgaver.json) fra da det ble fylt. Byttes én av dem, er arkivet utdatert, uansett hvilken:
 * dokumentene leses fra filene og kan ha endret seg under mappene og behandlingene i arkivet.
 */
final class UtgaveTest extends AppTestCase
{
    public function testArkivetErUtdatertNaarEnAvDatafileneErEndret(): void
    {
        // Kopier av datafilene, så testen kan endre dem. Innholdet er det samme; bare filen er en annen.
        $data = dirname(__DIR__, 2) . '/data';
        $sirevag = $this->rot . '/sirevag.json';
        $oppgaver = $this->rot . '/oppgaver.json';
        self::assertTrue(copy($data . '/sirevag.json', $sirevag));
        self::assertTrue(copy($data . '/oppgaver.json', $oppgaver));
        // Klassene lages på nytt for hver forespørsel i drift, så her også: da leses filene slik de er nå.
        $ovingsarkiv = function () use ($sirevag, $oppgaver): Ovingsarkiv {
            $grunndata = new Grunndata($sirevag);

            return new Ovingsarkiv($this->db, $this->klokke, $grunndata, new Register($this->db, $this->klokke, $grunndata), new Oppgavesett($oppgaver));
        };
        $arkivId = $ovingsarkiv()->forBruker(Tilfeldig::uuid());
        self::assertFalse($ovingsarkiv()->oversikt($arkivId)['utdatert'], 'Et arkiv som nettopp er fylt, er ikke utdatert.');

        // Et linjeskift til sist endrer filen uten å endre innholdet: fingeravtrykket gjelder filen.
        $endre = static function (string $fil): string {
            $for = (string) file_get_contents($fil);
            self::assertNotSame('', $for);
            self::assertNotFalse(file_put_contents($fil, $for . "\n"));

            return $for;
        };

        $for = $endre($sirevag);
        self::assertTrue($ovingsarkiv()->oversikt($arkivId)['utdatert'], 'Mappene som følger med, er endret.');
        self::assertNotFalse(file_put_contents($sirevag, $for));
        self::assertFalse($ovingsarkiv()->oversikt($arkivId)['utdatert'], 'Med den opprinnelige filen tilbake er arkivet ikke utdatert.');

        $for = $endre($oppgaver);
        self::assertTrue($ovingsarkiv()->oversikt($arkivId)['utdatert'], 'Dokumentene i postmottaket er endret.');
        self::assertNotFalse(file_put_contents($oppgaver, $for));
        self::assertFalse($ovingsarkiv()->oversikt($arkivId)['utdatert']);

        // En nullstilling fyller arkivet med utgaven som gjelder nå.
        $endre($sirevag);
        $endre($oppgaver);
        self::assertTrue($ovingsarkiv()->oversikt($arkivId)['utdatert']);
        $ovingsarkiv()->nullstill($arkivId);
        self::assertFalse($ovingsarkiv()->oversikt($arkivId)['utdatert'], 'Etter nullstillingen er arkivet fylt med den nye utgaven.');
    }
}
