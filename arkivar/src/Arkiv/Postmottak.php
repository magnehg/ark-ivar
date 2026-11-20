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
 * Arkivet låses mens en registrering lagres, så to forespørsler samtidig ikke gir dobbel registrering.
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
        $handling = Felt::tekst($inn['handling'] ?? null);
        if (!in_array($handling, Kodelister::HANDLINGER, true)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.handling');
        }
        if (in_array($handling, Kodelister::I_ARKIVET, true)) {
            // Et dokument som skal i arkivet, registreres i mappen det hører til.
            throw new HttpFeil(422, 'krever_mappe', 'arkivar.feil.krever_mappe');
        }

        return $this->db->transaksjon(function () use ($arkivId, $oppgave, $handling): array {
            $this->register->laas($arkivId);
            $this->krevUbehandlet($arkivId, $oppgave['id']);
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
        if ($this->register->sak($arkivId, $sakId) === null) {
            throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        }
        $oppgaveId = trim(Felt::tekst($inn['oppgave'] ?? null));
        $oppgave = $oppgaveId !== '' ? ($this->oppgaver->hent($oppgaveId) ?? throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.oppgave')) : null;
        $idag = Dato::idag($this->klokke);
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
        // Et dokument fra postmottaket som arkiveres uten journalføring, har ingen journalposttype å velge: det er
        // inngående. I en egen registrering velger studenten typen selv.
        $type = $journalfor || $oppgave === null ? Felt::tekst($inn['type'] ?? null) : 'I';
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
        if (mb_strlen($offentlig, 'UTF-8') > 500) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.offentlig_tittel');
        }
        $felt = [
            'type' => $type,
            'status' => $journalfor ? Kodelister::JP_JOURNALFORT : Kodelister::JP_ARKIVERT,
            'tittel' => $tittel,
            'offentlig_tittel' => $skjermTittel ? ($offentlig !== '' ? $offentlig : '*****') : null,
            'dokumentdato' => $dokumentdato,
            'journaldato' => $idag,
            'tilgangskode' => $tilgangskode !== '' ? $tilgangskode : null,
            'skjerm_tittel' => $skjermTittel,
            'skjerm_part' => $skjermPart,
            'part_navn' => $part,
            'journalfort' => $journalfor,
            'oppgave_id' => $oppgave['id'] ?? null,
        ];

        return $this->db->transaksjon(function () use ($arkivId, $sakId, $oppgave, $felt): array {
            // Låsen gjør at to registreringer i samme arkiv går etter hverandre: sjekkene under gjelder da fortsatt
            // når journalposten lagres (samme dokument sendt to ganger, mappen slettet i en annen fane).
            $this->register->laas($arkivId);
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if (Felt::tekst($sak['status']) === Kodelister::SAK_AVSLUTTET) {
                throw new HttpFeil(409, 'mappe_avsluttet', 'arkivar.feil.mappe_avsluttet');
            }
            if ($oppgave !== null) {
                $this->krevUbehandlet($arkivId, $oppgave['id']);
            }
            $journalpostId = $this->register->opprettJournalpost($arkivId, $sakId, $felt + ['saksbehandler' => Felt::tekst($sak['saksansvarlig']), 'enhet' => Felt::tekst($sak['enhet'])]);
            $post = $this->register->journalpost($arkivId, $journalpostId) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern');
            $poeng = null;
            $maks = null;
            if ($oppgave !== null) {
                $handling = $felt['journalfort'] ? Kodelister::JOURNALFOR : Kodelister::ARKIVER;
                [$poeng, $maks] = $this->lagre($arkivId, $oppgave, $handling, $journalpostId, $this->svar($arkivId, $sak, $post, $oppgave));
                // Dokumentet kan ha gjort mappen til en blanding av saker: da er mappen feil for de andre også.
                $this->revurder($arkivId, $sakId, $oppgave['id']);
            }

            return ['journalpost' => $this->visJournalpost($post), 'oppgave' => $oppgave['id'] ?? null, 'poeng' => $poeng, 'maks' => $maks];
        });
    }

    /**
     * Sletter en journalpost studenten har laget uten dokument fra postmottaket. En registrering av et dokument fra
     * postmottaket fjernes ved å angre behandlingen, og dokumentene som fulgte med arkivet, kan ikke slettes. Er
     * dokumentet tatt ut av postmottaket siden (arkivet er fylt med en eldre utgave av innholdet), kan behandlingen
     * ikke angres der: da slettes journalposten her, sammen med behandlingen. Gir løpenummeret journalposten hadde
     * (til loggen).
     */
    public function slettJournalpost(string $arkivId, string $id): string
    {
        return $this->db->transaksjon(function () use ($arkivId, $id): string {
            $this->register->laas($arkivId);
            $post = $this->register->journalpost($arkivId, $id) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $oppgaveId = Felt::valgfriTekst($post['oppgave_id']);
            if ($oppgaveId !== null && $this->oppgaver->hent($oppgaveId) !== null) {
                throw new HttpFeil(409, 'journalpost_fra_postmottak', 'arkivar.feil.journalpost_fra_postmottak');
            }
            if ($post['startdokument'] !== null) {
                throw new HttpFeil(409, 'journalpost_fulgte_med', 'arkivar.feil.journalpost_fulgte_med');
            }
            $this->register->slettJournalpost($arkivId, $id);
            if ($oppgaveId !== null) {
                $this->db->utfor('DELETE FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
            }

            return Felt::tall($post['journalaar']) . '/' . Felt::tall($post['sekvens']);
        });
    }

    /**
     * Angrer behandlingen: journalposten slettes, og dokumentet kan behandles på nytt. Mappen blir stående, og de
     * andre dokumentene fra postmottaket i den vurderes på nytt (mappen kan ha blitt riktig for dem).
     */
    public function angre(string $arkivId, string $oppgaveId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $oppgaveId): void {
            $this->register->laas($arkivId);
            $status = $this->db->en('SELECT * FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
            if ($status === null) {
                return;
            }
            $post = is_string($status['journalpost_id']) ? $this->register->journalpost($arkivId, $status['journalpost_id']) : null;
            if ($post !== null) {
                $this->register->slettJournalpost($arkivId, Felt::tekst($post['id']));
            }
            $this->db->utfor('DELETE FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
            if ($post !== null) {
                $this->revurder($arkivId, Felt::tekst($post['sak_id']));
            }
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
     * Vurderer dokumentene fra postmottaket i mappen på nytt, uten $unntatt. Om mappen er riktig for et dokument,
     * avhenger av hva som ellers ligger i den, så vurderingen må følge med når et dokument kommer til eller tas ut.
     */
    private function revurder(string $arkivId, string $sakId, ?string $unntatt = null): void
    {
        $sak = $this->register->sak($arkivId, $sakId);
        if ($sak === null) {
            return;
        }
        foreach ($this->register->journalposter($arkivId, $sakId) as $post) {
            $oppgaveId = Felt::valgfriTekst($post['oppgave_id']);
            $oppgave = $oppgaveId !== null && $oppgaveId !== $unntatt ? $this->oppgaver->hent($oppgaveId) : null;
            if ($oppgave === null) {
                continue;
            }
            $svar = $this->svar($arkivId, $sak, $post, $oppgave);
            $vurdering = Vurdering::vurder($oppgave, $svar, $this->grunndata);
            $this->db->utfor(
                'UPDATE arkivar_oppgavestatus SET svar = ?, vurdering = ?, poeng = ?, maks = ? WHERE arkiv_id = ? AND oppgave_id = ?',
                [
                    json_encode($svar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($vurdering, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    count(array_filter($vurdering, static fn (array $k): bool => $k['ok'])), count($vurdering), $arkivId, $oppgave['id'],
                ],
            );
        }
    }

    /**
     * Svaret som vurderes: det studenten har registrert, lest fra mappen og journalposten.
     *
     * @param array<string, mixed> $sak
     * @param array<string, mixed> $post
     * @param Oppgave $oppgave
     * @return Svar
     */
    private function svar(string $arkivId, array $sak, array $post, array $oppgave): array
    {
        $sakSkjermet = Felt::tall($sak['skjerm_tittel']) === 1;

        return [
            'handling' => Felt::tall($post['journalfort']) === 1 ? Kodelister::JOURNALFOR : Kodelister::ARKIVER,
            // En mappe uten nøkkel har studenten opprettet selv.
            'sak_ny' => $sak['nokkel'] === null,
            'sak_nokkel' => Felt::valgfriTekst($sak['nokkel']),
            'sak_tekst' => Felt::tall($sak['saksaar']) . '/' . Felt::tall($sak['sekvens']) . ' ' . Felt::tekst($sak['tittel']),
            'sak_offentlig' => $sakSkjermet ? Felt::tekst($sak['offentlig_tittel'], '*****') : Felt::tekst($sak['tittel']),
            'sak_skjermet' => $sakSkjermet,
            'sak_alene' => $this->aleneIMappen($arkivId, Felt::tekst($sak['id']), $oppgave),
            'arkivdel' => Felt::tekst($sak['arkivdel']),
            'klasse' => Felt::tekst($sak['klasse']),
            'skjerm_klasse' => Felt::tall($sak['skjerm_klasse']) === 1,
            'type' => Felt::tekst($post['type']),
            'tittel' => Felt::tekst($post['tittel']),
            'offentlig_tittel' => Felt::valgfriTekst($post['offentlig_tittel']),
            'tilgangskode' => Felt::valgfriTekst($post['tilgangskode']),
            'skjerm_tittel' => Felt::tall($post['skjerm_tittel']) === 1,
            'skjerm_part' => Felt::tall($post['skjerm_part']) === 1,
            'dokumentdato' => Felt::tekst($post['dokumentdato']),
            'part_navn' => Felt::tekst($post['part_navn']),
        ];
    }

    /**
     * Journalposten slik den sendes til nettleseren. En journalpost der dokumentet er tatt ut av postmottaket siden
     * den ble registrert, har ikke noe dokument å vise og ingen behandling å angre: den vises som en registrering
     * studenten kan slette.
     *
     * @param array<string, mixed> $rad
     * @return array<string, mixed>
     */
    public function visJournalpost(array $rad, bool $offentlig = false): array
    {
        $ut = $this->register->visJournalpost($rad, $offentlig);
        $oppgaveId = Felt::valgfriTekst($rad['oppgave_id']);
        $oppgave = $oppgaveId !== null ? $this->oppgaver->hent($oppgaveId) : null;
        if ($oppgaveId !== null && $oppgave === null) {
            $ut['oppgave'] = null;
            $ut['har_dokument'] = false;
            $ut['dokumentstatus'] = null;
            $ut['variantformat'] = null;
            $ut['kan_slettes'] = !$offentlig;
        }
        // Dokumentfilene (produksjonsformat og arkivformat). Den offentlige journalen har ikke dokumentene.
        $startdokument = Felt::valgfriTekst($rad['startdokument'] ?? null);
        $dokument = $oppgave ?? ($oppgaveId === null && $startdokument !== null ? $this->grunndata->startdokument($startdokument) : null);
        if ($oppgaveId === null && $startdokument !== null && $dokument === null) {
            // Dokumentet som fulgte med, er tatt ut av grunndataene siden arkivet ble fylt: det er ikke noe å åpne.
            $ut['har_dokument'] = false;
            $ut['dokumentstatus'] = null;
            $ut['variantformat'] = null;
        }
        $ut['filer'] = $offentlig || $dokument === null
            ? []
            : Dokumentfiler::filer($dokument['kanal'], $dokument['mottatt'], Felt::tekst($rad['status']), Felt::tekst($rad['tittel']));

        return $ut;
    }

    /**
     * Om dokumentet er alene om mappen blant dokumentene fra postmottaket. Et dokument som starter en ny sak, skal
     * ha sin egen mappe: en mappe der studenten samler ulike saker, er feil for alle dokumentene i den.
     *
     * @param Oppgave $oppgave
     */
    private function aleneIMappen(string $arkivId, string $sakId, array $oppgave): bool
    {
        foreach ($this->register->oppgaverISak($arkivId, $sakId, $oppgave['id']) as $annenId) {
            // Et dokument som er tatt ut av postmottaket siden, sier ingenting om hvilken sak mappen gjelder.
            $annen = $this->oppgaver->hent($annenId);
            if ($annen !== null && Oppgavesett::sak($annen) !== Oppgavesett::sak($oppgave)) {
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
            'handling' => $handling, 'sak_ny' => false, 'sak_nokkel' => null, 'sak_tekst' => '', 'sak_offentlig' => '', 'sak_skjermet' => false, 'sak_alene' => true,
            'arkivdel' => '', 'klasse' => '', 'skjerm_klasse' => false, 'type' => '', 'tittel' => '', 'offentlig_tittel' => null, 'tilgangskode' => null,
            'skjerm_tittel' => false, 'skjerm_part' => false, 'dokumentdato' => '', 'part_navn' => '',
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
            'journalpost' => $post !== null ? $this->visJournalpost($post) : null,
            'sak' => $sak !== null ? $this->register->visSak($sak) : null,
        ];
    }
}
