<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Dokumentene studenten skriver selv i en mappe, fra en dokumentmal: utgående brev, foreløpig svar, internt
 * notat, møtereferat eller et inngående brev på blankt ark. Dokumentet blir en journalpost med status R (reservert)
 * og dokumentstatus B (under redigering) til det ferdigstilles; da blir journalposten ekspedert (utgående) eller
 * journalført (de andre), og dokumentet får arkivformat. Hver versjon av dokumentet er en rad i arkivar_dokument,
 * og den høyeste er den gjeldende. Et ferdigstilt dokument kan ikke endres.
 *
 * Dokumentet er avsnitt med tekst (et avsnitt som begynner med «## », er en mellomtittel), som dokumentene i
 * grunndataene. Det er ingen formatering å rense: teksten vises alltid som tekst.
 *
 * @phpstan-import-type Dokument from Grunndata
 * @phpstan-import-type Dokumentmal from Dokumentmaler
 * @phpstan-type Innhold array{
 *     kanal: string,
 *     mottatt: ?string,
 *     avsender: array{navn: string, adresse: ?string, epost: ?string},
 *     mottaker: ?string,
 *     emne: string,
 *     tekst: list<string>,
 *     vedlegg: list<string>,
 * }
 * @phpstan-type Oversiktsrad array{mal: string, versjoner: int, kanal: string, mottatt: ?string, vedlegg: list<string>}
 */
final class Skriving
{
    private const MAKS_EMNE = 300;
    private const MAKS_MOTTAKER = 500;
    private const MAKS_AVSNITT = 80;
    private const MAKS_AVSNITTLENGDE = 5000;
    private const MAKS_VEDLEGG = 20;
    private const MAKS_VEDLEGGSLENGDE = 200;

    /** @var array<string, array<string, Oversiktsrad>> det skrevne dokumentet per journalpost, per arkiv */
    private array $oversikt = [];

    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke,
        private readonly Grunndata $grunndata,
        private readonly Dokumentmaler $maler,
        private readonly Register $register,
    ) {
    }

    /**
     * De skrevne dokumentene i arkivet, etter journalposten: malen, antall versjoner, og kanalen, mottaksdatoen og
     * vedleggene i den gjeldende versjonen. Hentes én gang per forespørsel, så en liste med mange journalposter
     * ikke gir ett oppslag per rad.
     *
     * @return array<string, Oversiktsrad>
     */
    public function oversikt(string $arkivId): array
    {
        if (isset($this->oversikt[$arkivId])) {
            return $this->oversikt[$arkivId];
        }
        $ut = [];
        foreach ($this->db->alle('SELECT journalpost_id, versjon, mal, innhold FROM arkivar_dokument WHERE arkiv_id = ? ORDER BY journalpost_id, versjon', [$arkivId]) as $rad) {
            $innhold = self::tolk(Felt::tekst($rad['innhold']));
            // Radene kommer i stigende versjon, så den siste for hver journalpost er den gjeldende.
            $ut[Felt::tekst($rad['journalpost_id'])] = [
                'mal' => Felt::tekst($rad['mal']), 'versjoner' => Felt::tall($rad['versjon']),
                'kanal' => $innhold['kanal'], 'mottatt' => $innhold['mottatt'], 'vedlegg' => $innhold['vedlegg'],
            ];
        }

        return $this->oversikt[$arkivId] = $ut;
    }

    /**
     * Oppretter journalposten og første versjon av dokumentet. Gir ID-en til journalposten.
     *
     * @param array<string, mixed> $inn JSON-kroppen: mal, type, registreringsfeltene (Registreringsfelt), part_navn og
     *                                  dokumentet (emne, tekst, mottaker, avsender og vedlegg)
     */
    public function opprett(string $arkivId, string $sakId, array $inn): string
    {
        $mal = $this->maler->hent(Felt::tekst($inn['mal'] ?? null)) ?? throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.dokumentmal');
        $idag = Dato::idag($this->klokke);
        $felt = $this->felt($inn, $mal, $idag);

        return $this->db->transaksjon(function () use ($arkivId, $sakId, $inn, $mal, $felt, $idag): string {
            $this->register->laas($arkivId);
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if (Felt::tekst($sak['status']) === Kodelister::SAK_AVSLUTTET) {
                throw new HttpFeil(409, 'mappe_avsluttet', 'arkivar.feil.mappe_avsluttet');
            }
            $enhet = Felt::tekst($sak['enhet']);
            // Et inngående brev på blankt ark er mottatt i dag. Kommunens egne dokumenter har ingen mottaksdato.
            $innhold = $this->innhold($inn, $mal, $enhet, $mal['avsender'] === Dokumentmaler::BLANK ? $idag : null);
            $id = $this->register->opprettJournalpost($arkivId, $sakId, $felt + [
                'status' => Kodelister::JP_RESERVERT, 'journalfort' => false, 'journaldato' => $idag,
                'saksbehandler' => Felt::tekst($sak['saksansvarlig']), 'enhet' => $enhet,
                'dokumentstatus' => Kodelister::DOK_UNDER_REDIGERING, 'variantformat' => Kodelister::VARIANT_PRODUKSJON,
            ]);
            $this->lagreVersjon($arkivId, $id, 1, $mal['kode'], $innhold);

            return $id;
        });
    }

    /**
     * Lagrer endringer i et dokument som er under redigering. Med «ny_versjon» blir teksten en ny versjon, ellers
     * erstatter den den gjeldende. Registreringsfeltene på journalposten lagres samtidig.
     *
     * @param array<string, mixed> $inn som opprett(), og ny_versjon
     */
    public function lagre(string $arkivId, string $journalpostId, array $inn): void
    {
        $idag = Dato::idag($this->klokke);
        $this->db->transaksjon(function () use ($arkivId, $journalpostId, $inn, $idag): void {
            $this->register->laas($arkivId);
            [$post, $mal, $gjeldende, $versjon] = $this->underRedigering($arkivId, $journalpostId);
            $felt = $this->felt($inn, $mal, $idag);
            $innhold = $this->innhold($inn, $mal, Felt::tekst($post['enhet']), $gjeldende['mottatt']);
            $this->register->oppdaterJournalpost($arkivId, $journalpostId, $felt);
            if (($inn['ny_versjon'] ?? false) === true) {
                $this->lagreVersjon($arkivId, $journalpostId, $versjon + 1, $mal['kode'], $innhold);
            } else {
                $this->db->utfor(
                    'UPDATE arkivar_dokument SET innhold = ?, endret = ? WHERE journalpost_id = ? AND versjon = ?',
                    [json_encode($innhold, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), Db::tid($this->klokke->now()), $journalpostId, $versjon],
                );
                unset($this->oversikt[$arkivId]);
            }
        });
    }

    /**
     * Ferdigstiller dokumentet: det kan ikke endres mer, journalposten blir ekspedert (utgående) eller journalført
     * (de andre) i dag, og dokumentet får arkivformat.
     */
    public function ferdigstill(string $arkivId, string $journalpostId): void
    {
        $idag = Dato::idag($this->klokke);
        $this->db->transaksjon(function () use ($arkivId, $journalpostId, $idag): void {
            $this->register->laas($arkivId);
            [$post] = $this->underRedigering($arkivId, $journalpostId);
            $status = Felt::tekst($post['type']) === 'U' ? Kodelister::JP_EKSPEDERT : Kodelister::JP_JOURNALFORT;
            $this->register->ferdigstillJournalpost($arkivId, $journalpostId, $status, $idag);
        });
    }

    /**
     * Dokumentet til en journalpost studenten har skrevet, i formen arket i grensesnittet bruker. Uten $versjon gis
     * den gjeldende. Null når journalposten ikke har noe skrevet dokument, eller versjonen ikke finnes.
     *
     * @param array<string, mixed> $post raden fra arkivar_journalpost
     * @return Dokument|null
     */
    public function dokument(string $arkivId, array $post, ?int $versjon = null): ?array
    {
        $rad = $versjon === null
            ? $this->db->en('SELECT innhold FROM arkivar_dokument WHERE arkiv_id = ? AND journalpost_id = ? ORDER BY versjon DESC LIMIT 1', [$arkivId, Felt::tekst($post['id'])])
            : $this->db->en('SELECT innhold FROM arkivar_dokument WHERE arkiv_id = ? AND journalpost_id = ? AND versjon = ?', [$arkivId, Felt::tekst($post['id']), $versjon]);
        if ($rad === null) {
            return null;
        }
        $innhold = self::tolk(Felt::tekst($rad['innhold']));

        return [
            'kanal' => $innhold['kanal'], 'dokumentdato' => Felt::tekst($post['dokumentdato']), 'mottatt' => $innhold['mottatt'],
            'avsender' => $innhold['avsender'], 'mottaker' => $innhold['mottaker'], 'emne' => $innhold['emne'],
            'tekst' => $innhold['tekst'], 'vedlegg' => $innhold['vedlegg'], 'utkast' => [],
        ];
    }

    /**
     * Journalposten, malen, den gjeldende versjonen og nummeret på den, for et dokument som fortsatt kan endres.
     *
     * @return array{array<string, mixed>, Dokumentmal, Innhold, int}
     */
    private function underRedigering(string $arkivId, string $journalpostId): array
    {
        $post = $this->register->journalpost($arkivId, $journalpostId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        $rad = $this->db->en('SELECT versjon, mal, innhold FROM arkivar_dokument WHERE arkiv_id = ? AND journalpost_id = ? ORDER BY versjon DESC LIMIT 1', [$arkivId, $journalpostId]);
        if ($rad === null) {
            throw new HttpFeil(409, 'ikke_skrevet', 'arkivar.feil.ikke_skrevet');
        }
        if (Felt::valgfriTekst($post['dokumentstatus']) !== Kodelister::DOK_UNDER_REDIGERING) {
            throw new HttpFeil(409, 'dokument_ferdigstilt', 'arkivar.feil.dokument_ferdigstilt');
        }
        $mal = $this->maler->hent(Felt::tekst($rad['mal'])) ?? throw new HttpFeil(409, 'ikke_skrevet', 'arkivar.feil.dokumentmal');

        return [$post, $mal, self::tolk(Felt::tekst($rad['innhold'])), Felt::tall($rad['versjon'])];
    }

    /**
     * Feltene på journalposten. Typen må være en av dem malen tillater.
     *
     * @param array<string, mixed> $inn
     * @param Dokumentmal $mal
     * @return array{tittel: string, offentlig_tittel: ?string, dokumentdato: string, tilgangskode: ?string, skjerm_tittel: bool, skjerm_part: bool, type: string, part_navn: string}
     */
    private function felt(array $inn, array $mal, string $idag): array
    {
        $type = Felt::tekst($inn['type'] ?? null);
        if (!in_array($type, $mal['typer'], true) || !in_array($type, Kodelister::JOURNALPOSTTYPER, true)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.journalposttype');
        }

        return Registreringsfelt::les($inn, $this->grunndata, $idag) + ['type' => $type, 'part_navn' => Registreringsfelt::part($inn['part_navn'] ?? null, $type, true)];
    }

    /**
     * Dokumentet slik det lagres. Avsenderen er kommunen med enheten som har saken, eller den studenten har
     * skrevet på et blankt ark.
     *
     * @param array<string, mixed> $inn
     * @param Dokumentmal $mal
     * @return Innhold
     */
    private function innhold(array $inn, array $mal, string $enhet, ?string $mottatt): array
    {
        $emne = trim(Felt::tekst($inn['emne'] ?? null));
        $lengde = mb_strlen($emne, 'UTF-8');
        if ($lengde < 3 || $lengde > self::MAKS_EMNE) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.emne');
        }
        $tekst = self::linjer($inn['tekst'] ?? null, self::MAKS_AVSNITT, self::MAKS_AVSNITTLENGDE, 'arkivar.feil.dokumenttekst');
        if ($tekst === []) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.dokumenttekst');
        }
        $mottaker = trim(Felt::tekst($inn['mottaker'] ?? null));
        if (mb_strlen($mottaker, 'UTF-8') > self::MAKS_MOTTAKER) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.mottaker');
        }
        if ($mal['avsender'] === Dokumentmaler::BLANK) {
            $a = is_array($inn['avsender'] ?? null) ? $inn['avsender'] : [];
            $navn = trim(Felt::tekst($a['navn'] ?? null));
            $adresse = trim(Felt::tekst($a['adresse'] ?? null));
            $epost = trim(Felt::tekst($a['epost'] ?? null));
            if (mb_strlen($navn, 'UTF-8') < 2 || mb_strlen($navn, 'UTF-8') > 200 || mb_strlen($adresse, 'UTF-8') > 200 || mb_strlen($epost, 'UTF-8') > 200) {
                throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.avsender');
            }
            $avsender = ['navn' => $navn, 'adresse' => $adresse !== '' ? $adresse : null, 'epost' => $epost !== '' ? $epost : null];
        } else {
            $virksomhet = $this->grunndata->virksomhet();
            $avsender = ['navn' => $virksomhet['navn'] . ', ' . $this->grunndata->enhetsnavn($enhet), 'adresse' => $virksomhet['adresse'], 'epost' => $virksomhet['epost']];
        }

        return [
            'kanal' => $mal['kanal'], 'mottatt' => $mottatt, 'avsender' => $avsender, 'mottaker' => $mottaker !== '' ? $mottaker : null,
            'emne' => $emne, 'tekst' => $tekst, 'vedlegg' => self::linjer($inn['vedlegg'] ?? null, self::MAKS_VEDLEGG, self::MAKS_VEDLEGGSLENGDE, 'arkivar.feil.vedlegg'),
        ];
    }

    /**
     * En liste med tekster fra skjemaet: tomme tas bort, og for mange eller for lange avvises.
     *
     * @return list<string>
     */
    private static function linjer(mixed $verdi, int $maks, int $makslengde, string $feil): array
    {
        $ut = [];
        foreach (is_array($verdi) ? $verdi : [] as $linje) {
            $tekst = is_string($linje) ? trim($linje) : '';
            if ($tekst === '') {
                continue;
            }
            if (!mb_check_encoding($tekst, 'UTF-8') || mb_strlen($tekst, 'UTF-8') > $makslengde) {
                throw new HttpFeil(422, 'ugyldig_felt', $feil);
            }
            $ut[] = $tekst;
        }
        if (count($ut) > $maks) {
            throw new HttpFeil(422, 'ugyldig_felt', $feil);
        }

        return $ut;
    }

    /** @param Innhold $innhold */
    private function lagreVersjon(string $arkivId, string $journalpostId, int $versjon, string $mal, array $innhold): void
    {
        $naa = Db::tid($this->klokke->now());
        $this->db->utfor(
            'INSERT INTO arkivar_dokument (journalpost_id, versjon, arkiv_id, mal, innhold, opprettet, endret) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$journalpostId, $versjon, $arkivId, $mal, json_encode($innhold, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $naa, $naa],
        );
        unset($this->oversikt[$arkivId]);
    }

    /**
     * Innholdet lest fra databasen. Felt som mangler, blir tomme: en rad kan aldri velte en liste.
     *
     * @return Innhold
     */
    private static function tolk(string $json): array
    {
        $d = json_decode($json, true);
        $d = is_array($d) ? $d : [];
        $a = is_array($d['avsender'] ?? null) ? $d['avsender'] : [];

        return [
            'kanal' => Felt::tekst($d['kanal'] ?? null, 'brev'), 'mottatt' => Felt::valgfriTekst($d['mottatt'] ?? null),
            'avsender' => ['navn' => Felt::tekst($a['navn'] ?? null), 'adresse' => Felt::valgfriTekst($a['adresse'] ?? null), 'epost' => Felt::valgfriTekst($a['epost'] ?? null)],
            'mottaker' => Felt::valgfriTekst($d['mottaker'] ?? null), 'emne' => Felt::tekst($d['emne'] ?? null),
            'tekst' => Grunndata::tekster($d['tekst'] ?? null), 'vedlegg' => Grunndata::tekster($d['vedlegg'] ?? null),
        ];
    }
}
