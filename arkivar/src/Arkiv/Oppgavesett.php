<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Felt;

/**
 * Eksempeldokumentene i postmottaket (arkivar/data/oppgaver.json): brev og e-poster til Sirevåg kommune som
 * studenten skal vurdere og registrere, hvert med en fasit og forklaringer. Dokumentene er oppdiktet. Fasiten
 * sendes aldri til nettleseren før studenten har behandlet dokumentet.
 *
 * Fasitens sak sier om dokumentet skal i en mappe som finnes i arkivet (type «eksisterende» med mappens nøkkel),
 * eller om det starter en ny sak (type «ny»). «klasse» er primærklasseringene som godtas. Har en ny sak en
 * «offentlig_tittel», skal tittelen på mappen skjermes; ellers skal den stå åpent. «part» er kortformer av
 * avsenderen som også godtas, for eksempel «Statsforvalteren» når avsenderen er «Statsforvalteren i Rogaland».
 *
 * @phpstan-type Fasitsak array{
 *     type: string,
 *     nokkel: ?string,
 *     tittel: ?string,
 *     offentlig_tittel: ?string,
 *     arkivdel: string,
 *     klasse: list<string>,
 * }
 * @phpstan-type Fasit array{
 *     handling: string,
 *     sak: ?Fasitsak,
 *     type: ?string,
 *     tittel: ?string,
 *     offentlig_tittel: ?string,
 *     tilgangskode: list<string>,
 *     skjerm_tittel: ?bool,
 *     skjerm_part: ?bool,
 *     part: list<string>,
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

    private function fil(): string
    {
        return $this->fil ?? dirname(__DIR__, 2) . '/data/oppgaver.json';
    }

    /** Utgaven av oppgavesettet: et fingeravtrykk av filen. */
    public function utgave(): string
    {
        return Grunndata::fingeravtrykk($this->fil());
    }

    /** @return list<Oppgave> */
    public function alle(): array
    {
        if ($this->oppgaver !== null) {
            return $this->oppgaver;
        }
        $data = Grunndata::les($this->fil());
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
                'tekst' => Grunndata::tekster($r['tekst'] ?? null),
                'vedlegg' => Grunndata::tekster($r['vedlegg'] ?? null),
                'fasit' => self::fasit(is_array($r['fasit'] ?? null) ? $r['fasit'] : []),
            ];
        }

        return $this->oppgaver = $ut;
    }

    /**
     * ID-ene til oppgavene som finnes nå. Et arkiv som er fylt med en eldre utgave, kan ha behandlinger av
     * dokumenter som siden er tatt ut: de telles ikke.
     *
     * @return list<string>
     */
    public function ider(): array
    {
        return array_column($this->alle(), 'id');
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
     * Saken dokumentet hører til etter fasiten, som en nøkkel til sammenlikning: mappen som finnes i arkivet, eller
     * den nye saken dokumentet starter (hvert dokument som starter en ny sak, starter sin egen). Null når dokumentet
     * ikke skal i arkivet.
     *
     * @param Oppgave $oppgave
     */
    public static function sak(array $oppgave): ?string
    {
        $sak = $oppgave['fasit']['sak'];
        if ($sak === null) {
            return null;
        }

        return $sak['type'] === 'eksisterende' ? 'mappe:' . $sak['nokkel'] : 'ny:' . $oppgave['id'];
    }

    /**
     * Dokumentet slik studenten ser det i postmottaket, uten fasit. Mottakeren er alltid kommunen.
     *
     * @param Oppgave $oppgave
     * @return array<string, mixed>
     */
    public static function dokument(array $oppgave): array
    {
        return array_diff_key($oppgave, ['fasit' => true]) + ['mottaker' => null];
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
                'klasse' => Grunndata::tekster($s['klasse'] ?? null),
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
            'tilgangskode' => Grunndata::tekster($f['tilgangskode'] ?? null),
            'skjerm_tittel' => is_bool($f['skjerm_tittel'] ?? null) ? $f['skjerm_tittel'] : null,
            'skjerm_part' => is_bool($f['skjerm_part'] ?? null) ? $f['skjerm_part'] : null,
            // Andre navn på avsenderen som godtas, når avsenderen i dokumentet er skrevet med flere ledd.
            'part' => Grunndata::tekster($f['part'] ?? null),
            'sensitive_ord' => Grunndata::tekster($f['sensitive_ord'] ?? null),
            'forklaring' => $forklaring,
        ];
    }
}
