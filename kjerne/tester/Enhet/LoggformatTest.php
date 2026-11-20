<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Enhet;

use Bivrost\Kjerne\Logg\CloudEvent;
use Bivrost\Kjerne\Logg\Hendelse;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Logg\LoggLager;
use Bivrost\Kjerne\Logg\Syslog;
use Bivrost\Kjerne\Logg\VideresendendeLoggLager;
use PHPUnit\Framework\TestCase;

/** CloudEvents 1.0, syslog RFC 5424 og OCSF-tilordningen. */
final class LoggformatTest extends TestCase
{
    private function hendelse(string $hva = 'urn:bivrost:hendelse:innlogging:avvist', string $kat = 'sikkerhet'): Hendelse
    {
        return new Hendelse(
            '2b1d7e8a-1c2d-4e5f-8a9b-0c1d2e3f4a5b',
            '2026-11-13T10:00:00.000Z',
            ['kode' => $kat, 'tekst' => 'x'],
            ['urn' => $hva, 'tekst' => 'Mislykket innlogging'],
            ['type' => 'bruker', 'type_tekst' => 'Bruker', 'id' => 'sub-1', 'navn' => 'Kari "K" Nordmann]', 'tittel' => null],
            null,
            [],
            [],
            [],
            ['kode' => 'nett', 'tekst' => 'Nettleser'],
            '2b1d7e8a-1c2d-4e5f-8a9b-0c1d2e3f4a5c'
        );
    }

    public function testCloudEvent(): void
    {
        $c = CloudEvent::fraHendelse($this->hendelse(), 'gerd');
        self::assertSame('1.0', $c['specversion']);
        self::assertSame('urn:bivrost:app:gerd', $c['source']);
        self::assertSame('urn:bivrost:hendelse:innlogging:avvist', $c['type']);
        self::assertSame(3002, $c['ocsfclassuid']);
        self::assertArrayNotHasKey('subject', $c);
        foreach (array_keys($c) as $k) {
            self::assertMatchesRegularExpression('/^[a-z0-9]{1,20}$/', $k, 'Navn på attributter etter CloudEvents § 3.1.1');
        }
    }

    public function testSyslogRfc5424(): void
    {
        $m = Syslog::melding($this->hendelse(), 'gerd', 'tjener 1', Syslog::EKSEMPEL_PEN);
        self::assertMatchesRegularExpression('/^<84>1 2026-11-13T10:00:00\.000Z tjener1 bivrost-gerd - bivrost:hendelse:innlogging:avvi \[bivrost@32473 /', $m);
        // Uten eget foretaksnummer: ingen strukturerte data (NILVALUE), hendelsen står som JSON i meldingen (BIV-B-026).
        $uten = Syslog::melding($this->hendelse(), 'gerd', 'tjener 1');
        self::assertMatchesRegularExpression('/^<84>1 \S+ tjener1 bivrost-gerd - bivrost:hendelse:innlogging:avvi - \x{FEFF}\{/u', $uten);
        self::assertStringNotContainsString('32473', $uten);
        // OCSF-tilordningen følger med i meldingen, også uten strukturerte data.
        $json = json_decode(substr($uten, (int) strpos($uten, "\u{FEFF}") + 3), true);
        self::assertIsArray($json);
        self::assertIsArray($json['ocsf'] ?? null);
        self::assertSame(3002, $json['ocsf']['class_uid'] ?? null);
        self::assertIsArray($json['hva'] ?? null);
        self::assertSame('urn:bivrost:hendelse:innlogging:avvist', $json['hva']['urn'] ?? null);
        self::assertSame(12345, Syslog::pen(' 12345 '));
        self::assertNull(Syslog::pen(''));
        foreach (['0', '-1', 'abc', '12 34', '01'] as $feil) {
            try {
                Syslog::pen($feil);
                self::fail("«{$feil}» skulle vært avvist.");
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertStringContainsString('hvem="Kari \\"K\\" Nordmann\\]"', $m, 'Tegnene " og ] skrives med \\ (RFC 5424 § 6.3.3).');
        self::assertStringContainsString('ocsf_class_uid="3002"', $m);
        self::assertStringStartsWith('<133>1 ', Syslog::melding($this->hendelse('urn:bivrost:hendelse:oppdatering:filer', 'revisjon'), 'gerd'));
    }

    public function testVideresendingStopperAldriLoggen(): void
    {
        $lager = new class implements LoggLager {
            public int $skrevet = 0;

            public function skriv(Hendelse $h): void
            {
                $this->skrevet++;
            }

            public function les(LoggFilter $filter, int $antall = 200, ?int $forNr = null): array
            {
                return [];
            }

            public function hverEn(LoggFilter $filter, callable $hver): int
            {
                return 0;
            }

            public function ryddSikkerhetslogg(int $dager): int
            {
                return 0;
            }
        };
        $sendt = [];
        (new VideresendendeLoggLager($lager, 'udp://127.0.0.1:514', 'gerd', function (string $m) use (&$sendt): void {
            $sendt[] = $m;
        }))->skriv($this->hendelse());
        self::assertSame(1, $lager->skrevet);
        self::assertCount(1, $sendt);
        (new VideresendendeLoggLager($lager, 'udp://127.0.0.1:514', 'gerd', static function (): void {
            throw new \RuntimeException('nede');
        }))->skriv($this->hendelse());
        self::assertSame(2, $lager->skrevet);
        $this->expectException(\InvalidArgumentException::class);
        new VideresendendeLoggLager($lager, 'http://x', 'gerd');
    }
}
