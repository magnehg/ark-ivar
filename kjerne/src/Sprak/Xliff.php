<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sprak;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Eksport og import av oversettelser i XLIFF 2.0 (OASIS, BIV-SPR-06), så Weblate, Crowdin, Lokalise og andre
 * oversettelsesverktøy kan brukes. Kilden er alltid ICU MessageFormat i JSON; XLIFF er utvekslingsformatet.
 * Én <file> per modulfil (id = filnavnet uten .json), én <unit> per nøkkel (id = nøkkelen), <source> på kildespråket
 * og <target> på målspråket (tom når teksten mangler, med state="initial"). ICU-teksten står uendret i elementene.
 */
final class Xliff
{
    private const NS = 'urn:oasis:names:tc:xliff:document:2.0';

    public static function eksporter(string $i18n, string $kilde, string $maal): string
    {
        $d = new \DOMDocument('1.0', 'UTF-8');
        $d->formatOutput = true;
        $x = $d->createElementNS(self::NS, 'xliff');
        $x->setAttribute('version', '2.0');
        $x->setAttribute('srcLang', $kilde);
        $x->setAttribute('trgLang', $maal);
        $d->appendChild($x);
        $filer = Felt::ellers(glob("$i18n/$kilde/*.json"), []);
        sort($filer);
        foreach ($filer as $fil) {
            if (basename($fil) === 'sprakpakke.json') {
                continue;
            }
            $kildeTekster = self::lesJson($fil);
            $maalFil = "$i18n/$maal/" . basename($fil);
            $maalTekster = is_file($maalFil) ? self::lesJson($maalFil) : [];
            $f = $d->createElementNS(self::NS, 'file');
            $f->setAttribute('id', basename($fil, '.json'));
            $x->appendChild($f);
            foreach ($kildeTekster as $k => $v) {
                $u = $d->createElementNS(self::NS, 'unit');
                $u->setAttribute('id', $k);
                $s = $d->createElementNS(self::NS, 'segment');
                $s->setAttribute('state', isset($maalTekster[$k]) ? 'translated' : 'initial');
                $src = $d->createElementNS(self::NS, 'source');
                $src->appendChild($d->createTextNode($v));
                $s->appendChild($src);
                $trg = $d->createElementNS(self::NS, 'target');
                $trg->appendChild($d->createTextNode($maalTekster[$k] ?? ''));
                $s->appendChild($trg);
                $u->appendChild($s);
                $f->appendChild($u);
            }
        }

        return (string) $d->saveXML();
    }

    /** @return array<string, string> */
    private static function lesJson(string $fil): array
    {
        $j = json_decode((string) file_get_contents($fil), true);
        if (!is_array($j)) {
            throw new BivrostFeil('bivrost.feil.sprak.fil');
        }
        $ut = [];
        foreach ($j as $k => $v) {
            if (is_string($v)) {
                $ut[(string) $k] = $v;
            }
        }

        return $ut;
    }

    /**
     * Leser en XLIFF 2.0-fil og gir målspråket og tekstene per modulfil (tomme mål hoppes over).
     *
     * @return array{sprak: string, filer: array<string, array<string, string>>}
     */
    public static function importer(string $xml): array
    {
        $d = new \DOMDocument();
        // Ingen eksterne entiteter eller nettverk (XXE).
        if (
            $xml === '' || !$d->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA) || $d->documentElement === null
            || $d->documentElement->namespaceURI !== self::NS || $d->documentElement->getAttribute('version') !== '2.0'
        ) {
            throw new BivrostFeil('bivrost.feil.sprak.xliff');
        }
        $maal = $d->documentElement->getAttribute('trgLang');
        if (preg_match(Sprakpakke::KODE, $maal) !== 1) {
            throw new BivrostFeil('bivrost.feil.sprak.xliff');
        }
        $filer = [];
        foreach ($d->getElementsByTagNameNS(self::NS, 'file') as $f) {
            $id = $f->getAttribute('id');
            if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) !== 1) {
                throw new BivrostFeil('bivrost.feil.sprak.xliff');
            }
            foreach ($f->getElementsByTagNameNS(self::NS, 'unit') as $u) {
                $trg = $u->getElementsByTagNameNS(self::NS, 'target')->item(0);
                $tekst = $trg !== null ? $trg->textContent : '';
                if ($tekst !== '') {
                    $filer[$id][$u->getAttribute('id')] = $tekst;
                }
            }
        }

        return ['sprak' => $maal, 'filer' => $filer];
    }

    /**
     * Skriver de importerte tekstene til i18n/<språk>/<modul>.json (sortert, ICU-kontrollert). Gir antall tekster.
     */
    public static function skriv(string $i18n, string $xml): int
    {
        $r = self::importer($xml);
        $n = 0;
        foreach ($r['filer'] as $modul => $tekster) {
            foreach ($tekster as $k => $v) {
                if (!Sprakkontroll::gyldigIcu($v)) {
                    throw new BivrostFeil('bivrost.feil.sprak.icu', ['nokkel' => $k]);
                }
            }
            ksort($tekster);
            $mappe = "$i18n/{$r['sprak']}";
            if (!is_dir($mappe)) {
                mkdir($mappe, 0755, true);
            }
            file_put_contents("$mappe/$modul.json", json_encode($tekster, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $n += count($tekster);
        }

        return $n;
    }
}
