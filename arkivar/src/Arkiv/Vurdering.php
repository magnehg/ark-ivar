<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

/**
 * Vurderingen av en registrering mot fasiten for et eksempeldokument. Gir en liste med kriterier, hvert med om
 * studentens valg stemmer, hva studenten valgte, hva fasiten sier, og forklaringen fra fasiten.
 *
 * Bare det som kan avgjøres sikkert, vurderes: behandlingen, saken, arkivdelen, klassen, journalposttypen,
 * tilgangskoden, skjermingen, dokumentdatoen og avsenderen. Titler er fritekst og vurderes ikke, bortsett fra at
 * de offentlige titlene (journalpostens, og sakens når studenten har opprettet den) ikke må inneholde navn
 * fasiten sier er sensitive. Er behandlingen feil (for eksempel
 * journalført noe som ikke skal i arkivet), vurderes ikke resten.
 *
 * @phpstan-import-type Oppgave from Oppgavesett
 * @phpstan-type Svar array{
 *     handling: string,
 *     sak_ny: bool,
 *     sak_nokkel: ?string,
 *     sak_tekst: string,
 *     sak_offentlig: string,
 *     arkivdel: string,
 *     klasse: string,
 *     type: string,
 *     tittel: string,
 *     offentlig_tittel: ?string,
 *     tilgangskode: ?string,
 *     skjerm_tittel: bool,
 *     skjerm_part: bool,
 *     dokumentdato: string,
 *     part_navn: string,
 * }
 * @phpstan-type Kriterium array{id: string, ok: bool, ditt: string, riktig: string, forklaring: string}
 */
final class Vurdering
{
    /**
     * @param Oppgave $oppgave
     * @param Svar $svar
     * @return list<Kriterium>
     */
    public static function vurder(array $oppgave, array $svar, Grunndata $grunndata): array
    {
        $fasit = $oppgave['fasit'];
        $forklaring = $fasit['forklaring'];
        $ut = [self::kriterium('handling', $svar['handling'] === $fasit['handling'], $svar['handling'], $fasit['handling'], $forklaring['handling'] ?? '')];
        $sak = $fasit['sak'];
        if ($svar['handling'] !== $fasit['handling'] || $sak === null) {
            return $ut;
        }
        $ny = $sak['type'] === 'ny';
        $riktigSak = $ny ? $svar['sak_ny'] : (!$svar['sak_ny'] && $svar['sak_nokkel'] === $sak['nokkel']);
        $ut[] = self::kriterium('sak', $riktigSak, $svar['sak_ny'] ? 'ny' : $svar['sak_tekst'], $ny ? 'ny' : 'eksisterende', $forklaring['sak'] ?? '');
        $ut[] = self::kriterium(
            'arkivdel',
            $svar['arkivdel'] === $sak['arkivdel'],
            $grunndata->arkivdeltekst($svar['arkivdel']),
            $grunndata->arkivdeltekst($sak['arkivdel']),
            $forklaring['arkivdel'] ?? '',
        );
        $ut[] = self::kriterium(
            'klasse',
            in_array($svar['klasse'], $sak['klasse'], true),
            $grunndata->klassetekst($svar['klasse']),
            implode(' / ', array_map($grunndata->klassetekst(...), $sak['klasse'])),
            $forklaring['klasse'] ?? '',
        );
        $ut[] = self::kriterium('dokumentdato', $svar['dokumentdato'] === $oppgave['dokumentdato'], $svar['dokumentdato'], $oppgave['dokumentdato'], '');
        if ($fasit['handling'] !== Kodelister::JOURNALFOR) {
            return $ut;
        }
        if ($fasit['type'] !== null) {
            $ut[] = self::kriterium('type', $svar['type'] === $fasit['type'], $svar['type'], $fasit['type'], '');
        }
        $ut[] = self::kriterium('part', self::sammeNavn($svar['part_navn'], $oppgave['avsender']['navn']), $svar['part_navn'], $oppgave['avsender']['navn'], '');
        $valgt = $svar['tilgangskode'] ?? '';
        $riktigTilgang = $fasit['tilgangskode'] === [] ? $valgt === '' : in_array($valgt, $fasit['tilgangskode'], true);
        $ut[] = self::kriterium(
            'tilgang',
            $riktigTilgang,
            $grunndata->tilgangstekst($svar['tilgangskode']),
            implode(' / ', array_map($grunndata->tilgangstekst(...), $fasit['tilgangskode'])),
            $forklaring['tilgang'] ?? '',
        );
        $skjerming = $forklaring['skjerming'] ?? '';
        if ($fasit['sensitive_ord'] !== [] && $svar['sak_ny']) {
            // En ny sak har studenten selv gitt tittel: heller ikke den kan røpe navnene i den offentlige journalen.
            $ut[] = self::kriterium('offentlig_sakstittel', !self::harOrd($svar['sak_offentlig'], $fasit['sensitive_ord']), $svar['sak_offentlig'], '', $skjerming);
        }
        if ($fasit['sensitive_ord'] !== []) {
            // Det som teller, er hva den offentlige journalen viser: tittelen selv, eller den offentlige tittelen når den er skjermet.
            $vises = $svar['skjerm_tittel'] ? ($svar['offentlig_tittel'] ?? '') : $svar['tittel'];
            $ut[] = self::kriterium('offentlig_tittel', !self::harOrd($vises, $fasit['sensitive_ord']), $vises !== '' ? $vises : '*****', '', $skjerming);
        } elseif ($fasit['skjerm_tittel'] === false) {
            $ut[] = self::kriterium('skjerm_tittel', !$svar['skjerm_tittel'], $svar['skjerm_tittel'] ? 'ja' : 'nei', 'nei', $skjerming);
        }
        if ($fasit['skjerm_part'] !== null) {
            $ut[] = self::kriterium('skjerm_part', $svar['skjerm_part'] === $fasit['skjerm_part'], $svar['skjerm_part'] ? 'ja' : 'nei', $fasit['skjerm_part'] ? 'ja' : 'nei', $skjerming);
        }

        return $ut;
    }

    /** @return Kriterium */
    private static function kriterium(string $id, bool $ok, string $ditt, string $riktig, string $forklaring): array
    {
        return ['id' => $id, 'ok' => $ok, 'ditt' => $ditt, 'riktig' => $riktig, 'forklaring' => $forklaring];
    }

    /** Samme navn når det ene inneholder det andre, uten hensyn til store bokstaver, tegnsetting og mellomrom. */
    private static function sammeNavn(string $svar, string $fasit): bool
    {
        $a = self::forenkle($svar);
        $b = self::forenkle($fasit);

        return mb_strlen($a, 'UTF-8') >= 3 && (str_contains($b, $a) || str_contains($a, $b));
    }

    /** @param list<string> $ord */
    private static function harOrd(string $tekst, array $ord): bool
    {
        $forenklet = self::forenkle($tekst);
        foreach ($ord as $o) {
            if (str_contains($forenklet, self::forenkle($o))) {
                return true;
            }
        }

        return false;
    }

    private static function forenkle(string $tekst): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($tekst, 'UTF-8'));
    }
}
