<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

/**
 * Vurderingen av en registrering mot fasiten for et eksempeldokument. Gir en liste med kriterier, hvert med om
 * studentens valg stemmer, hva studenten valgte, hva fasiten sier, og forklaringen fra fasiten.
 *
 * Bare det som kan avgjøres sikkert, vurderes: behandlingen, mappen (en som finnes, eller en ny som dokumentet har
 * for seg selv), arkivdelen, primærklasseringen, journalposttypen, tilgangskoden, skjermingen, dokumentdatoen og
 * avsenderen. Titler er fritekst og vurderes ikke, bortsett fra at de offentlige titlene (journalpostens, og
 * mappens når studenten har opprettet den) ikke må inneholde navn fasiten sier er sensitive.
 *
 * Skjerming vurderes begge veier: det som røper et navn, skal skjermes, og det som ikke røper noe, skal stå åpent.
 * På en mappe studenten har opprettet, gjelder det både tittelen og klasseringen: klasseringen skjermes i
 * personarkivene (fødselsdato og navn) og ellers ikke. Er behandlingen feil (for eksempel journalført noe som ikke
 * skal i arkivet), vurderes ikke resten.
 *
 * @phpstan-import-type Oppgave from Oppgavesett
 * @phpstan-type Svar array{
 *     handling: string,
 *     sak_ny: bool,
 *     sak_nokkel: ?string,
 *     sak_tekst: string,
 *     sak_offentlig: string,
 *     sak_skjermet: bool,
 *     sak_alene: bool,
 *     arkivdel: string,
 *     klasse: string,
 *     skjerm_klasse: bool,
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
    /** Ord som ikke teller når navnet på avsenderen sammenliknes: småord og selskapsformer. */
    private const SMAAORD = ['og', 'ved', 'v', 'i', 'for', 'att', 'as', 'asa', 'iks', 'kf', 'sa'];

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
        // En ny sak skal ha sin egen mappe, opprettet av studenten. En sak som finnes, skal i mappen som fulgte med.
        $egenMappe = $svar['sak_ny'] && $svar['sak_alene'];
        $riktigSak = $ny ? $egenMappe : (!$svar['sak_ny'] && $svar['sak_nokkel'] === $sak['nokkel']);
        $ut[] = self::kriterium('sak', $riktigSak, $egenMappe ? 'ny' : $svar['sak_tekst'], $ny ? 'ny' : 'eksisterende', $forklaring['sak'] ?? '');
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
            $grunndata->klassetekst($svar['arkivdel'], $svar['klasse']),
            implode(' / ', array_map(static fn (string $kode): string => $grunndata->klassetekst($sak['arkivdel'], $kode), $sak['klasse'])),
            $forklaring['klasse'] ?? '',
        );
        $skjerming = $forklaring['skjerming'] ?? '';
        if ($svar['sak_ny']) {
            // Mappen har studenten opprettet selv. Klasseringen skal skjermes i personarkivene (fødselsdato og navn)
            // og ellers stå åpent. Det vurderes etter arkivdelen studenten har valgt, så en feil arkivdel ikke også
            // gir avvik her.
            $skalSkjermes = ($grunndata->arkivdel($svar['arkivdel'])['ordning'] ?? '') === Grunndata::PERSON;
            $ut[] = self::kriterium('skjerm_klasse', $svar['skjerm_klasse'] === $skalSkjermes, self::jaNei($svar['skjerm_klasse']), self::jaNei($skalSkjermes), $skjerming);
            if ($ny && $sak['offentlig_tittel'] === null) {
                // Tittelen på en ny mappe som ikke røper noe, skal stå åpent i den offentlige journalen.
                $ut[] = self::kriterium('skjerm_sakstittel', !$svar['sak_skjermet'], self::jaNei($svar['sak_skjermet']), self::jaNei(false), $skjerming);
            }
        }
        $ut[] = self::kriterium('dokumentdato', $svar['dokumentdato'] === $oppgave['dokumentdato'], $svar['dokumentdato'], $oppgave['dokumentdato'], '');
        if ($fasit['handling'] !== Kodelister::JOURNALFOR) {
            return self::enForklaringOmSkjerming($ut);
        }
        if ($fasit['type'] !== null) {
            $ut[] = self::kriterium('type', $svar['type'] === $fasit['type'], $svar['type'], $fasit['type'], '');
        }
        $navn = array_merge([$oppgave['avsender']['navn']], $fasit['part']);
        $riktigPart = array_filter($navn, static fn (string $n): bool => self::sammeNavn($svar['part_navn'], $n)) !== [];
        $ut[] = self::kriterium('part', $riktigPart, $svar['part_navn'], $oppgave['avsender']['navn'], '');
        $valgt = $svar['tilgangskode'] ?? '';
        $riktigTilgang = $fasit['tilgangskode'] === [] ? $valgt === '' : in_array($valgt, $fasit['tilgangskode'], true);
        $ut[] = self::kriterium(
            'tilgang',
            $riktigTilgang,
            $grunndata->tilgangstekst($svar['tilgangskode']),
            implode(' / ', array_map($grunndata->tilgangstekst(...), $fasit['tilgangskode'])),
            $forklaring['tilgang'] ?? '',
        );
        if ($fasit['sensitive_ord'] !== [] && $svar['sak_ny']) {
            // En ny sak har studenten selv gitt tittel: heller ikke den kan røpe navnene i den offentlige journalen.
            $ut[] = self::kriterium('offentlig_sakstittel', !self::harOrd($svar['sak_offentlig'], $fasit['sensitive_ord']), $svar['sak_offentlig'], '', $skjerming);
        }
        if ($fasit['sensitive_ord'] !== []) {
            // Det som teller, er hva den offentlige journalen viser: tittelen selv, eller den offentlige tittelen når den er skjermet.
            $vises = $svar['skjerm_tittel'] ? ($svar['offentlig_tittel'] ?? '') : $svar['tittel'];
            $ut[] = self::kriterium('offentlig_tittel', !self::harOrd($vises, $fasit['sensitive_ord']), $vises !== '' ? $vises : '*****', '', $skjerming);
        }
        if ($fasit['skjerm_tittel'] === false) {
            // Tittelen skal stå åpent. Har studenten selv skrevet et navn i den, er det likevel riktig å skjerme den.
            $utenGrunn = $svar['skjerm_tittel'] && !self::harOrd($svar['tittel'], $fasit['sensitive_ord']);
            $ut[] = self::kriterium('skjerm_tittel', !$utenGrunn, self::jaNei($svar['skjerm_tittel']), self::jaNei(false), $skjerming);
        }
        if ($fasit['skjerm_part'] !== null) {
            $ut[] = self::kriterium('skjerm_part', $svar['skjerm_part'] === $fasit['skjerm_part'], self::jaNei($svar['skjerm_part']), self::jaNei($fasit['skjerm_part']), $skjerming);
        }

        return self::enForklaringOmSkjerming($ut);
    }

    /**
     * Kriteriene om skjerming deler én forklaring i fasiten. Den vises én gang: ved det første av dem som avviker,
     * ellers ved det første av dem.
     *
     * @param list<Kriterium> $kriterier
     * @return list<Kriterium>
     */
    private static function enForklaringOmSkjerming(array $kriterier): array
    {
        $skjerming = ['skjerm_klasse', 'skjerm_sakstittel', 'offentlig_sakstittel', 'offentlig_tittel', 'skjerm_tittel', 'skjerm_part'];
        $valgt = null;
        foreach ($kriterier as $nr => $k) {
            if (in_array($k['id'], $skjerming, true) && ($valgt === null || ($kriterier[$valgt]['ok'] && !$k['ok']))) {
                $valgt = $nr;
            }
        }
        foreach ($kriterier as $nr => $k) {
            if (in_array($k['id'], $skjerming, true) && $nr !== $valgt) {
                $kriterier[$nr]['forklaring'] = '';
            }
        }

        return $kriterier;
    }

    /** @return Kriterium */
    private static function kriterium(string $id, bool $ok, string $ditt, string $riktig, string $forklaring): array
    {
        return ['id' => $id, 'ok' => $ok, 'ditt' => $ditt, 'riktig' => $riktig, 'forklaring' => $forklaring];
    }

    private static function jaNei(bool $verdi): string
    {
        return $verdi ? 'ja' : 'nei';
    }

    /**
     * Om studenten har skrevet navnet på avsenderen. Navnene sammenliknes ord for ord, uten hensyn til rekkefølge,
     * store bokstaver, tegnsetting, småord og selskapsform («Due, Dina» er «Dina Due», «Rune og Marit Måke» er
     * «Marit og Rune Måke», og «Jærrent» er «Jærrent AS»). Et navn på ett eller to ord må være helt med; av et
     * lengre navn må minst halvparten av ordene være med. Ett ord av et langt navn («Sirevåg») er ikke nok, og
     * deler av et ord teller ikke.
     */
    private static function sammeNavn(string $svar, string $fasit): bool
    {
        $ord = static function (string $tekst): array {
            $deler = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($tekst, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

            return array_values(array_unique(array_diff($deler === false ? [] : $deler, self::SMAAORD)));
        };
        $fasitord = $ord($fasit);
        $antall = count($fasitord);
        if ($antall === 0) {
            return false;
        }
        $felles = count(array_intersect($fasitord, $ord($svar)));

        return $felles >= ($antall <= 2 ? $antall : (int) ceil($antall / 2));
    }

    /**
     * Om et av ordene står i teksten som et eget ord, også med genitivs-s. Navnene i arkivet er korte dyrenavn, så
     * det sammenliknes ord for ord: «Mår» skal ikke gi treff i «må regne».
     *
     * @param list<string> $ord
     */
    private static function harOrd(string $tekst, array $ord): bool
    {
        $ordene = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($tekst, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($ord as $o) {
            $o = self::forenkle($o);
            if ($o !== '' && $ordene !== false && (in_array($o, $ordene, true) || in_array($o . 's', $ordene, true))) {
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
