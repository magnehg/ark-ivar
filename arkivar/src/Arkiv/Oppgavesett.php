<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Felt;

/**
 * Eksempeldokumentene i postmottaket (arkivar/data/oppgaver.json): brev og e-poster til Sirevåg kommune som
 * studenten skal vurdere og registrere, hvert med en fasit og forklaringer. Dokumentene er oppdiktet. Fasiten
 * sendes aldri til nettleseren før studenten har behandlet dokumentet.
 *
 * @phpstan-type Fasitsak array{type: string, nokkel: ?string, tittel: ?string, offentlig_tittel: ?string, arkivdel: string, klasse: list<string>}
 * @phpstan-type Fasit array{
 *     handling: string,
 *     sak: ?Fasitsak,
 *     type: ?string,
 *     tittel: ?string,
 *     offentlig_tittel: ?string,
 *     tilgangskode: list<string>,
 *     skjerm_tittel: ?bool,
 *     skjerm_part: ?bool,
 *     sensitive_ord: list<string>,
 *     forklaring: array<string, string>,
 * }
 * @phpstan-type Oppgave array{
 *     id: string,
 *     kanal: string,
 *     mottatt: string,
 *     dokumentdato: string,
 *     avsender: array{navn: string, adresse: ?string, epost: ?string},
 *     emne: string,
 *     tekst: list<string>,
 *     vedlegg: list<string>,
 *     fasit: Fasit,
 * }
 */
final class Oppgavesett
{
    /** @var list<Oppgave>|null */
    private ?array $oppgaver = null;

    public function __construct(private readonly ?string $fil = null)
    {
    }

    /** @return list<Oppgave> */
    public function alle(): array
    {
        if ($this->oppgaver !== null) {
            return $this->oppgaver;
        }
        $data = Grunndata::les($this->fil ?? dirname(__DIR__, 2) . '/data/oppgaver.json');
        $ut = [];
        foreach (Grunndata::rader($data, 'oppgaver') as $r) {
            $avsender = is_array($r['avsender'] ?? null) ? $r['avsender'] : [];
            $ut[] = [
                'id' => Felt::tekst($r['id'] ?? null),
                'kanal' => Felt::tekst($r['kanal'] ?? null, 'brev'),
                'mottatt' => Felt::tekst($r['mottatt'] ?? null),
                'dokumentdato' => Felt::tekst($r['dokumentdato'] ?? null),
                'avsender' => [
                    'navn' => Felt::tekst($avsender['navn'] ?? null),
                    'adresse' => Felt::valgfriTekst($avsender['adresse'] ?? null),
                    'epost' => Felt::valgfriTekst($avsender['epost'] ?? null),
                ],
                'emne' => Felt::tekst($r['emne'] ?? null),
                'tekst' => self::tekster($r['tekst'] ?? null),
                'vedlegg' => self::tekster($r['vedlegg'] ?? null),
                'fasit' => self::fasit(is_array($r['fasit'] ?? null) ? $r['fasit'] : []),
            ];
        }

        return $this->oppgaver = $ut;
    }

    /** @return Oppgave|null */
    public function hent(string $id): ?array
    {
        foreach ($this->alle() as $o) {
            if ($o['id'] === $id) {
                return $o;
            }
        }

        return null;
    }

    /**
     * Dokumentet slik studenten ser det i postmottaket, uten fasit.
     *
     * @param Oppgave $oppgave
     * @return array<string, mixed>
     */
    public static function dokument(array $oppgave): array
    {
        return array_diff_key($oppgave, ['fasit' => true]);
    }

    /** @return list<string> */
    private static function tekster(mixed $verdi): array
    {
        $ut = [];
        foreach (is_array($verdi) ? $verdi : [] as $tekst) {
            if (is_string($tekst)) {
                $ut[] = $tekst;
            }
        }

        return $ut;
    }

    /**
     * @param array<array-key, mixed> $f
     * @return Fasit
     */
    private static function fasit(array $f): array
    {
        $sak = null;
        if (is_array($f['sak'] ?? null)) {
            $s = $f['sak'];
            $sak = [
                'type' => Felt::tekst($s['type'] ?? null, 'ny'),
                'nokkel' => Felt::valgfriTekst($s['nokkel'] ?? null),
                'tittel' => Felt::valgfriTekst($s['tittel'] ?? null),
                'offentlig_tittel' => Felt::valgfriTekst($s['offentlig_tittel'] ?? null),
                'arkivdel' => Felt::tekst($s['arkivdel'] ?? null),
                'klasse' => self::tekster($s['klasse'] ?? null),
            ];
        }
        $forklaring = [];
        foreach (is_array($f['forklaring'] ?? null) ? $f['forklaring'] : [] as $nokkel => $tekst) {
            if (is_string($tekst)) {
                $forklaring[(string) $nokkel] = $tekst;
            }
        }

        return [
            'handling' => Felt::tekst($f['handling'] ?? null),
            'sak' => $sak,
            'type' => Felt::valgfriTekst($f['type'] ?? null),
            'tittel' => Felt::valgfriTekst($f['tittel'] ?? null),
            'offentlig_tittel' => Felt::valgfriTekst($f['offentlig_tittel'] ?? null),
            'tilgangskode' => self::tekster($f['tilgangskode'] ?? null),
            'skjerm_tittel' => is_bool($f['skjerm_tittel'] ?? null) ? $f['skjerm_tittel'] : null,
            'skjerm_part' => is_bool($f['skjerm_part'] ?? null) ? $f['skjerm_part'] : null,
            'sensitive_ord' => self::tekster($f['sensitive_ord'] ?? null),
            'forklaring' => $forklaring,
        ];
    }
}
