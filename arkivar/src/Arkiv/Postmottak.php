<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Postmottaket: eksempeldokumentene studenten skal behandle som arkivtjenesten i Sirevåg kommune. For hvert
 * dokument velger studenten hva som skal skje. Skal dokumentet til et annet system eller holdes utenfor arkivet,
 * registreres bare valget (registrer). Skal det i arkivet, finner eller oppretter studenten mappen først og
 * registrerer så dokumentet som en journalpost i den (journalpost), journalført eller arkivert uten journalføring.
 * Svaret vurderes mot fasiten og lagres i arkivar_oppgavestatus. En behandling kan angres og gjøres på nytt.
 *
 * I en mappe kan studenten også lage journalposter uten dokument fra postmottaket (egne registreringer). De
 * vurderes ikke, og kan slettes.
 *
 * @phpstan-import-type Oppgave from Oppgavesett
 * @phpstan-import-type Svar from Vurdering
 */
final class Postmottak
{
    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke,
        private readonly Grunndata $grunndata,
        private readonly Oppgavesett $oppgaver,
        private readonly Register $register,
    ) {
    }

    /**
     * Dokumentene i postmottaket med status for dette arkivet, eldste mottatt først.
     *
     * @return list<array<string, mixed>>
     */
    public function liste(string $arkivId): array
    {
        $status = [];
        foreach ($this->db->alle('SELECT oppgave_id, handling, poeng, maks FROM arkivar_oppgavestatus WHERE arkiv_id = ?', [$arkivId]) as $rad) {
            $status[Felt::tekst($rad['oppgave_id'])] = $rad;
        }
        $ut = [];
        foreach ($this->oppgaver->alle() as $o) {
            $s = $status[$o['id']] ?? null;
            $ut[] = [
                'id' => $o['id'], 'kanal' => $o['kanal'], 'mottatt' => $o['mottatt'], 'emne' => $o['emne'], 'avsender' => $o['avsender']['navn'],
                'behandlet' => $s !== null,
                'handling' => $s !== null ? Felt::tekst($s['handling']) : null,
                'poeng' => $s !== null ? Felt::tall($s['poeng']) : null,
                'maks' => $s !== null ? Felt::tall($s['maks']) : null,
            ];
        }
        usort($ut, static fn (array $a, array $b): int => [$a['mottatt'], $a['id']] <=> [$b['mottatt'], $b['id']]);

        return $ut;
    }

    /**
     * Ett dokument. Fasiten (vurderingen og forslagene til titler) er bare med når dokumentet er behandlet.
     *
     * @return array<string, mixed>
     */
    public function vis(string $arkivId, string $oppgaveId): array
    {
        $oppgave = $this->oppgave($oppgaveId);
        $status = $this->db->en('SELECT * FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
        $ut = ['dokument' => Grunndata::medSaksnumre(Oppgavesett::dokument($oppgave), $this->register->saksnumre($arkivId)), 'behandlet' => $status !== null];
        if ($status !== null) {
            $ut += $this->resultat($arkivId, $oppgave, $status);
        }

        return $ut;
    }

    /**
     * Registrerer at dokumentet ikke skal i arkivet: det sendes til et annet system eller holdes utenfor. Valget
     * vurderes mot fasiten. Gir det samme som vis() etterpå.
     *
     * @param array<string, mixed> $inn JSON-kroppen fra nettleseren: handling.
     * @return array<string, mixed>
     */
    public function registrer(string $arkivId, string $oppgaveId, array $inn): array
    {
        $oppgave = $this->oppgave($oppgaveId);
        $this->krevUbehandlet($arkivId, $oppgaveId);
        $handling = Felt::tekst($inn['handling'] ?? null);
        if (!in_array($handling, Kodelister::HANDLINGER, true)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.handling');
        }
        if (in_array($handling, Kodelister::I_ARKIVET, true)) {
            // Et dokument som skal i arkivet, registreres i mappen det hører til.
            throw new HttpFeil(422, 'krever_mappe', 'arkivar.feil.krever_mappe');
        }

        return $this->db->transaksjon(function () use ($arkivId, $oppgave, $handling): array {
            $this->lagre($arkivId, $oppgave, $handling, null, self::tomtSvar($handling));

            return $this->vis($arkivId, $oppgave['id']);
        });
    }

    /**
     * Registrerer en journalpost i en mappe. Med «oppgave» i skjemaet gjelder den et dokument fra postmottaket, og
     * registreringen vurderes mot fasiten; uten er den studentens egen registrering. «journalfores» sier om
     * dokumentet journalføres eller arkiveres uten journalføring.
     *
     * @param array<string, mixed> $inn JSON-kroppen: oppgave, journalfores, type, tittel, offentlig_tittel,
     *                                  dokumentdato, part_navn, tilgangskode, skjerm_tittel og skjerm_part.
     * @return array{journalpost: array<string, mixed>, oppgave: ?string, poeng: ?int, maks: ?int}
     */
    public function journalpost(string $arkivId, string $sakId, array $inn): array
    {
        $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        if (Felt::tekst($sak['status']) === Kodelister::SAK_AVSLUTTET) {
            throw new HttpFeil(409, 'mappe_avsluttet', 'arkivar.feil.mappe_avsluttet');
        }
        $oppgaveId = trim(Felt::tekst($inn['oppgave'] ?? null));
        $oppgave = null;
        if ($oppgaveId !== '') {
            $oppgave = $this->oppgaver->hent($oppgaveId) ?? throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.oppgave');
            $this->krevUbehandlet($arkivId, $oppgaveId);
        }
        $idag = $this->klokke->now()->format('Y-m-d');
        $journalfor = ($inn['journalfores'] ?? true) !== false;

        $tittel = trim(Felt::tekst($inn['tittel'] ?? null));
        $lengde = mb_strlen($tittel, 'UTF-8');
        if ($lengde < 3 || $lengde > 500) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.tittel');
        }
        $dokumentdato = Felt::tekst($inn['dokumentdato'] ?? null);
        $dato = \DateTimeImmutable::createFromFormat('!Y-m-d', $dokumentdato);
        if ($dato === false || $dato->format('Y-m-d') !== $dokumentdato || $dokumentdato > $idag) {
            throw new HttpFeil(422, 'ugyldig_dato', 'arkivar.feil.dokumentdato');
        }
        // Et dokument som arkiveres uten journalføring, har ingen journalposttype. Det kommer fra postmottaket og er inngående.
        $type = $journalfor ? Felt::tekst($inn['type'] ?? null) : 'I';
        if (!in_array($type, Kodelister::JOURNALPOSTTYPER, true)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.journalposttype');
        }
        $part = trim(Felt::tekst($inn['part_navn'] ?? null));
        if (mb_strlen($part, 'UTF-8') > 300 || ($journalfor && in_array($type, ['I', 'U'], true) && $part === '')) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.part');
        }
        $tilgangskode = trim(Felt::tekst($inn['tilgangskode'] ?? null));
        if ($tilgangskode !== '' && !$this->grunndata->harTilgangskode($tilgangskode)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.tilgangskode');
        }
        $skjermTittel = ($inn['skjerm_tittel'] ?? false) === true;
        $skjermPart = ($inn['skjerm_part'] ?? false) === true;
        if (($skjermTittel || $skjermPart) && $tilgangskode === '') {
            // Skjerming i journalen må ha en hjemmel, og hjemmelen følger tilgangskoden.
            throw new HttpFeil(422, 'skjerming_uten_hjemmel', 'arkivar.feil.skjerming_uten_hjemmel');
        }
        $offentlig = trim(Felt::tekst($inn['offentlig_tittel'] ?? null));
        $offentligTittel = $skjermTittel ? ($offentlig !== '' ? mb_substr($offentlig, 0, 500, 'UTF-8') : '*****') : null;

        return $this->db->transaksjon(function () use (
            $arkivId,
            $sak,
            $oppgave,
            $idag,
            $journalfor,
            $tittel,
            $dokumentdato,
            $type,
            $part,
            $tilgangskode,
            $skjermTittel,
            $skjermPart,
            $offentligTittel,
        ): array {
            $sakId = Felt::tekst($sak['id']);
            $journalpostId = $this->register->opprettJournalpost($arkivId, $sakId, [
                'type' => $type,
                'status' => $journalfor ? 'J' : 'A',
                'tittel' => $tittel,
                'offentlig_tittel' => $offentligTittel,
                'dokumentdato' => $dokumentdato,
                'journaldato' => $idag,
                'saksbehandler' => Felt::tekst($sak['saksansvarlig']),
                'enhet' => Felt::tekst($sak['enhet']),
                'tilgangskode' => $tilgangskode !== '' ? $tilgangskode : null,
                'skjerm_tittel' => $skjermTittel,
                'skjerm_part' => $skjermPart,
                'part_navn' => $part,
                'journalfort' => $journalfor,
                'oppgave_id' => $oppgave['id'] ?? null,
            ]);
            $poeng = null;
            $maks = null;
            if ($oppgave !== null) {
                $handling = $journalfor ? Kodelister::JOURNALFOR : Kodelister::ARKIVER;
                $svar = [
                    'handling' => $handling,
                    // En mappe uten nøkkel har studenten opprettet selv.
                    'sak_ny' => $sak['nokkel'] === null,
                    'sak_nokkel' => Felt::valgfriTekst($sak['nokkel']),
                    'sak_tekst' => Felt::tall($sak['saksaar']) . '/' . Felt::tall($sak['sekvens']) . ' ' . Felt::tekst($sak['tittel']),
                    'sak_offentlig' => Felt::tall($sak['skjerm_tittel']) === 1 ? Felt::tekst($sak['offentlig_tittel'], '*****') : Felt::tekst($sak['tittel']),
                    'sak_alene' => $this->aleneIMappen($arkivId, $sakId, $oppgave),
                    'arkivdel' => Felt::tekst($sak['arkivdel']),
                    'klasse' => Felt::tekst($sak['klasse']),
                    'skjerm_klasse' => Felt::tall($sak['skjerm_klasse']) === 1,
                    'type' => $type,
                    'tittel' => $tittel,
                    'offentlig_tittel' => $offentligTittel,
                    'tilgangskode' => $tilgangskode !== '' ? $tilgangskode : null,
                    'skjerm_tittel' => $skjermTittel,
                    'skjerm_part' => $skjermPart,
                    'dokumentdato' => $dokumentdato,
                    'part_navn' => $part,
                ];
                [$poeng, $maks] = $this->lagre($arkivId, $oppgave, $handling, $journalpostId, $svar);
            }
            $post = $this->register->journalpost($arkivId, $journalpostId) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern');

            return ['journalpost' => $this->register->visJournalpost($post), 'oppgave' => $oppgave['id'] ?? null, 'poeng' => $poeng, 'maks' => $maks];
        });
    }

    /**
     * Sletter en journalpost studenten har laget uten dokument fra postmottaket. En registrering av et dokument fra
     * postmottaket fjernes ved å angre behandlingen, og dokumentene som fulgte med arkivet, kan ikke slettes.
     */
    public function slettJournalpost(string $arkivId, string $id): void
    {
        $post = $this->register->journalpost($arkivId, $id) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        if ($post['oppgave_id'] !== null) {
            throw new HttpFeil(409, 'journalpost_fra_postmottak', 'arkivar.feil.journalpost_fra_postmottak');
        }
        if ($post['startdokument'] !== null) {
            throw new HttpFeil(409, 'journalpost_fulgte_med', 'arkivar.feil.journalpost_fulgte_med');
        }
        $this->register->slettJournalpost($arkivId, $id);
    }

    /** Angrer behandlingen: journalposten slettes, og dokumentet kan behandles på nytt. Mappen blir stående. */
    public function angre(string $arkivId, string $oppgaveId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $oppgaveId): void {
            $status = $this->db->en('SELECT * FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
            if ($status === null) {
                return;
            }
            if (is_string($status['journalpost_id'])) {
                $this->register->slettJournalpost($arkivId, $status['journalpost_id']);
            }
            $this->db->utfor('DELETE FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
        });
    }

    /** @return Oppgave */
    private function oppgave(string $id): array
    {
        return $this->oppgaver->hent($id) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
    }

    private function krevUbehandlet(string $arkivId, string $oppgaveId): void
    {
        if ($this->db->verdi('SELECT 1 FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]) !== null) {
            throw new HttpFeil(409, 'alt_behandlet', 'arkivar.feil.alt_behandlet');
        }
    }

    /**
     * Vurderer svaret mot fasiten og lagrer behandlingen. Gir poengene og hvor mange som var mulig.
     *
     * @param Oppgave $oppgave
     * @param Svar $svar
     * @return array{0: int, 1: int}
     */
    private function lagre(string $arkivId, array $oppgave, string $handling, ?string $journalpostId, array $svar): array
    {
        $vurdering = Vurdering::vurder($oppgave, $svar, $this->grunndata);
        $poeng = count(array_filter($vurdering, static fn (array $k): bool => $k['ok']));
        $this->db->utfor(
            'INSERT INTO arkivar_oppgavestatus (arkiv_id, oppgave_id, handling, journalpost_id, svar, vurdering, poeng, maks, behandlet) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $arkivId, $oppgave['id'], $handling, $journalpostId,
                json_encode($svar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($vurdering, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $poeng, count($vurdering), Db::tid($this->klokke->now()),
            ],
        );

        return [$poeng, count($vurdering)];
    }

    /**
     * Om dokumentet er alene om mappen, eller deler den bare med dokumenter som hører til samme sak etter fasiten.
     * Et dokument som starter en ny sak, skal ha sin egen mappe: en mappe der studenten samler ulike saker, er feil.
     *
     * @param Oppgave $oppgave
     */
    private function aleneIMappen(string $arkivId, string $sakId, array $oppgave): bool
    {
        foreach ($this->register->oppgaverISak($arkivId, $sakId, $oppgave['id']) as $annenId) {
            $annen = $this->oppgaver->hent($annenId);
            if ($annen === null || Oppgavesett::sak($annen) !== Oppgavesett::sak($oppgave)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Svaret når dokumentet ikke registreres i arkivet: bare behandlingen vurderes.
     *
     * @return Svar
     */
    private static function tomtSvar(string $handling): array
    {
        return [
            'handling' => $handling, 'sak_ny' => false, 'sak_nokkel' => null, 'sak_tekst' => '', 'sak_offentlig' => '', 'sak_alene' => true, 'arkivdel' => '', 'klasse' => '',
            'skjerm_klasse' => false, 'type' => '', 'tittel' => '', 'offentlig_tittel' => null, 'tilgangskode' => null, 'skjerm_tittel' => false, 'skjerm_part' => false,
            'dokumentdato' => '', 'part_navn' => '',
        ];
    }

    /**
     * Det som vises når dokumentet er behandlet: vurderingen, poengene, forslagene fra fasiten og registreringen.
     *
     * @param Oppgave $oppgave
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function resultat(string $arkivId, array $oppgave, array $status): array
    {
        $fasit = $oppgave['fasit'];
        $post = is_string($status['journalpost_id']) ? $this->register->journalpost($arkivId, $status['journalpost_id']) : null;
        $sak = $post !== null ? $this->register->sak($arkivId, Felt::tekst($post['sak_id'])) : null;
        $forslag = null;
        if ($fasit['sak'] !== null) {
            $fasitsak = $fasit['sak'];
            $sakstittel = $fasitsak['tittel'];
            if ($fasitsak['nokkel'] !== null) {
                $rad = $this->db->en('SELECT * FROM arkivar_sak WHERE arkiv_id = ? AND nokkel = ?', [$arkivId, $fasitsak['nokkel']]);
                $sakstittel = $rad !== null ? Felt::tall($rad['saksaar']) . '/' . Felt::tall($rad['sekvens']) . ' ' . Felt::tekst($rad['tittel']) : $sakstittel;
            }
            $forslag = ['sak' => $sakstittel, 'sak_offentlig' => $fasitsak['offentlig_tittel'], 'tittel' => $fasit['tittel'], 'offentlig_tittel' => $fasit['offentlig_tittel']];
        }

        return [
            'handling' => Felt::tekst($status['handling']),
            'vurdering' => json_decode(Felt::tekst($status['vurdering']), true),
            'poeng' => Felt::tall($status['poeng']),
            'maks' => Felt::tall($status['maks']),
            'forslag' => $forslag,
            'journalpost' => $post !== null ? $this->register->visJournalpost($post) : null,
            'sak' => $sak !== null ? $this->register->visSak($sak) : null,
        ];
    }
}
