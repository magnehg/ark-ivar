<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * Hendelser som syslog-meldinger etter RFC 5424 (BIV-STD-06), til valgfri videresending (VideresendendeLoggLager).
 * Fasilitet authpriv (10) for sikkerhetsloggen og local0 (16) for revisjonsloggen; alvorlighet warning (4) for
 * avviste forsøk og feil, ellers notice (5). Meldingen er hendelsen som JSON.
 *
 * Strukturerte data (RFC 5424 § 6.3) står under SD-ID-en «bivrost@<PEN>», der PEN er virksomhetens eget IANA Private
 * Enterprise Number (BIVROST_SYSLOG_PEN, BIV-B-026). Uten nummer sendes ingen strukturerte data («-»), fordi egne
 * SD-ID-er krever et tildelt nummer. Eksempelnummeret 32473 (RFC 5612) brukes bare i tester og dokumentasjon.
 */
final class Syslog
{
    /** Eksempelnummeret fra RFC 5612, bare til tester og dokumentasjon. */
    public const EKSEMPEL_PEN = 32473;

    /** SD-ID-en for et foretaksnummer: «bivrost@<PEN>» (RFC 5424 § 6.3.2). */
    public static function sdId(int $pen): string
    {
        if ($pen < 1) {
            throw new \InvalidArgumentException('BIVROST_SYSLOG_PEN må være et positivt heltall.');
        }

        return 'bivrost@' . $pen;
    }

    /** Foretaksnummeret fra .env, eller null når det ikke er satt. */
    public static function pen(?string $verdi): ?int
    {
        if ($verdi === null || trim($verdi) === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/D', trim($verdi)) !== 1) {
            throw new \InvalidArgumentException('BIVROST_SYSLOG_PEN må være et positivt heltall.');
        }

        return (int) trim($verdi);
    }

    public static function melding(Hendelse $h, string $app, string $vert = '-', ?int $pen = null): string
    {
        $sikkerhet = $h->kategori['kode'] === 'sikkerhet';
        $alvor = preg_match('/:(avvist|feil|separasjon_brudd)$/D', $h->hva['urn']) === 1 ? 4 : 5;
        $pri = ($sikkerhet ? 10 : 16) * 8 + $alvor;
        $sd = [
            'hva' => $h->hva['urn'],
            'hvem' => $h->hvem['navn'],
            'hvem_id' => (string) $h->hvem['id'],
            'korrelasjon' => $h->korrelasjon,
        ];
        $ocsf = Ocsf::klasse($h->hva['urn']);
        if ($ocsf !== null) {
            $sd['ocsf_class_uid'] = (string) $ocsf['class_uid'];
            $sd['ocsf_status_id'] = (string) $ocsf['status_id'];
        }
        $param = [];
        foreach ($sd as $k => $v) {
            $param[] = $k . '="' . strtr($v, ['\\' => '\\\\', '"' => '\\"', ']' => '\\]']) . '"';
        }
        // Meldingen er hendelsen som JSON, med OCSF-tilordningen under «ocsf» når hendelsen har en, så den følger med
        // også uten strukturerte data (uten BIVROST_SYSLOG_PEN, BIV-B-026).
        $melding = $h->tilListe();
        if ($ocsf !== null) {
            $melding['ocsf'] = $ocsf;
        }
        $msgid = substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '_', substr($h->hva['urn'], strlen('urn:'))), 0, 32);
        $vert = substr((string) preg_replace('/[^\x21-\x7E]/', '', $vert), 0, 255);

        return sprintf(
            '<%d>1 %s %s %s - %s %s %s',
            $pri,
            $h->tid,
            $vert === '' ? '-' : $vert,
            substr('bivrost-' . $app, 0, 48),
            $msgid,
            $pen === null ? '-' : '[' . self::sdId($pen) . ' ' . implode(' ', $param) . ']',
            "\u{FEFF}" . json_encode($melding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
