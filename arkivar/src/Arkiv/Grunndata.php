<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Felt;

/**
 * Grunndataene for Sirevåg kommune (arkivar/data/sirevag.json): arkivdelene, arkivnøkkelen (klassene), enhetene, de
 * ansatte, tilgangskodene og sakene som ligger i arkivet når en student får det. Dataene er felles for alle
 * øvingsarkivene og kan ikke endres av studentene. Arkivnøkkelen er laget for Ark-Ivar og er ikke KS' K-koder.
 *
 * @phpstan-type Arkivdel array{kode: string, tittel: string, beskrivelse: string}
 * @phpstan-type Klasse array{kode: string, tittel: string, forelder: ?string}
 * @phpstan-type Enhet array{kode: string, navn: string}
 * @phpstan-type Ansatt array{initialer: string, navn: string, tittel: string, enhet: string}
 * @phpstan-type Tilgangskode array{kode: string, navn: string, hjemmel: string}
 * @phpstan-type Startpost array{type: string, tittel: string, dokumentdato: string, part: string, status: string, tilgangskode: ?string, innhold: ?string}
 * @phpstan-type Startsak array{nokkel: string, tittel: string, arkivdel: string, klasse: string, enhet: string, saksansvarlig: string, saksdato: string, journalposter: list<Startpost>}
 */
final class Grunndata
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(?string $fil = null)
    {
        $this->data = self::les($fil ?? dirname(__DIR__, 2) . '/data/sirevag.json');
    }

    /**
     * Leser en JSON-fil med et objekt øverst.
     *
     * @return array<string, mixed>
     */
    public static function les(string $fil): array
    {
        $json = json_decode((string) file_get_contents($fil), true, 64, JSON_THROW_ON_ERROR);
        $ut = [];
        foreach (is_array($json) ? $json : [] as $nokkel => $verdi) {
            $ut[(string) $nokkel] = $verdi;
        }

        return $ut;
    }

    /**
     * Radene under en nøkkel, som matriser med tekstnøkler.
     *
     * @param array<array-key, mixed> $kilde
     * @return list<array<string, mixed>>
     */
    public static function rader(array $kilde, string $nokkel): array
    {
        $ut = [];
        foreach (is_array($kilde[$nokkel] ?? null) ? $kilde[$nokkel] : [] as $rad) {
            $ny = [];
            foreach (is_array($rad) ? $rad : [] as $felt => $verdi) {
                $ny[(string) $felt] = $verdi;
            }
            $ut[] = $ny;
        }

        return $ut;
    }

    /** @return array{navn: string, adresse: string, epost: string} */
    public function virksomhet(): array
    {
        $v = is_array($this->data['virksomhet'] ?? null) ? $this->data['virksomhet'] : [];

        return ['navn' => Felt::tekst($v['navn'] ?? null), 'adresse' => Felt::tekst($v['adresse'] ?? null), 'epost' => Felt::tekst($v['epost'] ?? null)];
    }

    /** @return list<Arkivdel> */
    public function arkivdeler(): array
    {
        return array_map(
            static fn (array $r): array => ['kode' => Felt::tekst($r['kode'] ?? null), 'tittel' => Felt::tekst($r['tittel'] ?? null), 'beskrivelse' => Felt::tekst($r['beskrivelse'] ?? null)],
            self::rader($this->data, 'arkivdeler'),
        );
    }

    /** @return list<Klasse> */
    public function klasser(): array
    {
        return array_map(
            static fn (array $r): array => ['kode' => Felt::tekst($r['kode'] ?? null), 'tittel' => Felt::tekst($r['tittel'] ?? null), 'forelder' => Felt::valgfriTekst($r['forelder'] ?? null)],
            self::rader($this->data, 'klasser'),
        );
    }

    /** @return list<Enhet> */
    public function enheter(): array
    {
        return array_map(
            static fn (array $r): array => ['kode' => Felt::tekst($r['kode'] ?? null), 'navn' => Felt::tekst($r['navn'] ?? null)],
            self::rader($this->data, 'enheter'),
        );
    }

    /** @return list<Ansatt> */
    public function ansatte(): array
    {
        return array_map(
            static fn (array $r): array => [
                'initialer' => Felt::tekst($r['initialer'] ?? null),
                'navn' => Felt::tekst($r['navn'] ?? null),
                'tittel' => Felt::tekst($r['tittel'] ?? null),
                'enhet' => Felt::tekst($r['enhet'] ?? null),
            ],
            self::rader($this->data, 'ansatte'),
        );
    }

    /** @return list<Tilgangskode> */
    public function tilgangskoder(): array
    {
        return array_map(
            static fn (array $r): array => ['kode' => Felt::tekst($r['kode'] ?? null), 'navn' => Felt::tekst($r['navn'] ?? null), 'hjemmel' => Felt::tekst($r['hjemmel'] ?? null)],
            self::rader($this->data, 'tilgangskoder'),
        );
    }

    /** @return list<Startsak> */
    public function saker(): array
    {
        $ut = [];
        foreach (self::rader($this->data, 'saker') as $r) {
            $poster = [];
            foreach (self::rader($r, 'journalposter') as $p) {
                $poster[] = [
                    'type' => Felt::tekst($p['type'] ?? null), 'tittel' => Felt::tekst($p['tittel'] ?? null), 'dokumentdato' => Felt::tekst($p['dokumentdato'] ?? null),
                    'part' => Felt::tekst($p['part'] ?? null), 'status' => Felt::tekst($p['status'] ?? null, 'J'),
                    'tilgangskode' => Felt::valgfriTekst($p['tilgangskode'] ?? null), 'innhold' => Felt::valgfriTekst($p['innhold'] ?? null),
                ];
            }
            $ut[] = [
                'nokkel' => Felt::tekst($r['nokkel'] ?? null), 'tittel' => Felt::tekst($r['tittel'] ?? null), 'arkivdel' => Felt::tekst($r['arkivdel'] ?? null),
                'klasse' => Felt::tekst($r['klasse'] ?? null), 'enhet' => Felt::tekst($r['enhet'] ?? null), 'saksansvarlig' => Felt::tekst($r['saksansvarlig'] ?? null),
                'saksdato' => Felt::tekst($r['saksdato'] ?? null), 'journalposter' => $poster,
            ];
        }

        return $ut;
    }

    public function harArkivdel(string $kode): bool
    {
        return in_array($kode, array_column($this->arkivdeler(), 'kode'), true);
    }

    /** En sak kan bare legges på en klasse på laveste nivå (tre sifre), ikke på en hovedgruppe. */
    public function kanKlassifiseres(string $kode): bool
    {
        foreach ($this->klasser() as $k) {
            if ($k['kode'] === $kode) {
                return $k['forelder'] !== null;
            }
        }

        return false;
    }

    public function harEnhet(string $kode): bool
    {
        return in_array($kode, array_column($this->enheter(), 'kode'), true);
    }

    public function harTilgangskode(string $kode): bool
    {
        return in_array($kode, array_column($this->tilgangskoder(), 'kode'), true);
    }

    /** @return Ansatt|null */
    public function ansatt(string $initialer): ?array
    {
        foreach ($this->ansatte() as $a) {
            if ($a['initialer'] === $initialer) {
                return $a;
            }
        }

        return null;
    }

    /** Den første ansatte i enheten: saksansvarlig når studenten ikke har valgt noen. */
    public function forsteIEnhet(string $enhet): string
    {
        foreach ($this->ansatte() as $a) {
            if ($a['enhet'] === $enhet) {
                return $a['initialer'];
            }
        }

        return $this->ansatte()[0]['initialer'] ?? '';
    }

    /** «520 Byggesaker», eller koden alene når den er ukjent. */
    public function klassetekst(string $kode): string
    {
        foreach ($this->klasser() as $k) {
            if ($k['kode'] === $kode) {
                return $k['kode'] . ' ' . $k['tittel'];
            }
        }

        return $kode;
    }

    public function arkivdeltekst(string $kode): string
    {
        foreach ($this->arkivdeler() as $a) {
            if ($a['kode'] === $kode) {
                return $a['tittel'];
            }
        }

        return $kode;
    }

    /** «Offl. § 25 første ledd (Tilsettingssaker)», eller tom tekst for ingen tilgangskode. */
    public function tilgangstekst(?string $kode): string
    {
        foreach ($this->tilgangskoder() as $t) {
            if ($t['kode'] === $kode) {
                return $t['hjemmel'] . ' (' . $t['navn'] . ')';
            }
        }

        return '';
    }
}
