<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * Én hendelse i det felles loggformatet (BIV-LOG-01) – samme for Bivrosts logg og appenes faglogg:
 *
 *   id           UUID
 *   tid          UTC, ISO 8601 med millisekunder (2026-11-13T10:00:00.000Z)
 *   kategori     {kode: revisjon | sikkerhet, tekst}; sikkerhetsloggen kan slettes etter oppbevaringstiden
 *   hva          {urn, tekst}: hendelsestypen, f.eks. urn:bivrost:hendelse:innlogging:lykkes
 *   hvem         {type, type_tekst, id, navn, tittel}
 *   objekt       {app, type, type_tekst, id, navn} eller null
 *   for, etter   {felt: {kode, tekst}} – aldri passord, nøkler eller tokener
 *   apper        [{id, navn}] – appene hendelsen gjelder (tom = på tvers)
 *   kilde        {kode: nett | api | kommandolinje | system, tekst}
 *   korrelasjon  UUID som er felles for hendelser som hører sammen
 *   data         ekstra opplysninger (med klartekst ved siden av koder)
 *
 * Klarteksten er et øyeblikksbilde på hovedspråket og endres aldri etterpå.
 */
final class Hendelse
{
    public const KATEGORIER = ['revisjon', 'sikkerhet'];

    /**
     * @param array{urn: string, tekst: string} $hva
     * @param array{kode: string, tekst: string} $kategori
     * @param array{type: string, type_tekst: string, id: ?string, navn: string, tittel: ?string} $hvem
     * @param array{app: ?string, type: string, type_tekst: string, id: ?string, navn: string}|null $objekt
     * @param array<string, array{kode: scalar|null, tekst: string}> $for
     * @param array<string, array{kode: scalar|null, tekst: string}> $etter
     * @param list<array{id: string, navn: string}> $apper
     * @param array{kode: string, tekst: string} $kilde
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tid,
        public readonly array $kategori,
        public readonly array $hva,
        public readonly array $hvem,
        public readonly ?array $objekt,
        public readonly array $for,
        public readonly array $etter,
        public readonly array $apper,
        public readonly array $kilde,
        public readonly string $korrelasjon,
        public readonly array $data = [],
    ) {
        if (!in_array($kategori['kode'], self::KATEGORIER, true)) {
            throw new \InvalidArgumentException('Ukjent loggkategori.');
        }
        if (preg_match('/^urn:[a-z0-9][a-z0-9-]{0,31}:[a-z0-9:_.-]+$/D', $hva['urn']) !== 1) {
            throw new \InvalidArgumentException('Hendelsestypen må være en URN.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $tid) !== 1) {
            throw new \InvalidArgumentException('Tiden må være UTC i ISO 8601.');
        }
    }

    /** @return array<string, mixed> */
    public function tilListe(): array
    {
        return [
            'id' => $this->id,
            'tid' => $this->tid,
            'kategori' => $this->kategori,
            'hva' => $this->hva,
            'hvem' => $this->hvem,
            'objekt' => $this->objekt,
            'for' => $this->for === [] ? new \stdClass() : $this->for,
            'etter' => $this->etter === [] ? new \stdClass() : $this->etter,
            'apper' => $this->apper,
            'kilde' => $this->kilde,
            'korrelasjon' => $this->korrelasjon,
            'data' => $this->data === [] ? new \stdClass() : $this->data,
        ];
    }

    /** @param array<string, mixed> $r Fra tilListe() etter JSON-dekoding. */
    public static function fraListe(array $r): self
    {
        $s = static fn (mixed $v): string => is_string($v) ? $v : '';
        $ns = static fn (mixed $v): ?string => is_string($v) ? $v : null;
        $l = static fn (mixed $v): array => is_array($v) ? $v : [];
        $hva = $l($r['hva'] ?? null);
        $kat = $l($r['kategori'] ?? null);
        $hvem = $l($r['hvem'] ?? null);
        $kilde = $l($r['kilde'] ?? null);
        $obj = is_array($r['objekt'] ?? null) ? $r['objekt'] : null;
        $verdier = static function (mixed $v) use ($s): array {
            $ut = [];
            foreach (is_array($v) ? $v : [] as $k => $x) {
                if (is_string($k) && is_array($x)) {
                    $kode = $x['kode'] ?? null;
                    $ut[$k] = ['kode' => is_scalar($kode) ? $kode : null, 'tekst' => $s($x['tekst'] ?? null)];
                }
            }

            return $ut;
        };
        $apper = [];
        foreach ($l($r['apper'] ?? null) as $a) {
            if (is_array($a)) {
                $apper[] = ['id' => $s($a['id'] ?? null), 'navn' => $s($a['navn'] ?? null)];
            }
        }
        $data = [];
        foreach ($l($r['data'] ?? null) as $k => $v) {
            if (is_string($k)) {
                $data[$k] = $v;
            }
        }

        return new self(
            $s($r['id'] ?? null),
            $s($r['tid'] ?? null),
            ['kode' => $s($kat['kode'] ?? null), 'tekst' => $s($kat['tekst'] ?? null)],
            ['urn' => $s($hva['urn'] ?? null), 'tekst' => $s($hva['tekst'] ?? null)],
            [
                'type' => $s($hvem['type'] ?? null), 'type_tekst' => $s($hvem['type_tekst'] ?? null),
                'id' => $ns($hvem['id'] ?? null), 'navn' => $s($hvem['navn'] ?? null), 'tittel' => $ns($hvem['tittel'] ?? null),
            ],
            $obj === null ? null : [
                'app' => $ns($obj['app'] ?? null), 'type' => $s($obj['type'] ?? null), 'type_tekst' => $s($obj['type_tekst'] ?? null),
                'id' => $ns($obj['id'] ?? null), 'navn' => $s($obj['navn'] ?? null),
            ],
            $verdier($r['for'] ?? null),
            $verdier($r['etter'] ?? null),
            $apper,
            ['kode' => $s($kilde['kode'] ?? null), 'tekst' => $s($kilde['tekst'] ?? null)],
            $s($r['korrelasjon'] ?? null),
            $data,
        );
    }
}
