<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Postmottaket: eksempeldokumentene studenten skal behandle som arkivtjenesten i Sirevåg kommune. For hvert
 * dokument velger studenten hva som skal skje (journalføre, arkivere uten journalføring, sende til et annet system
 * eller holde utenfor arkivet), og registrerer saken og journalposten når dokumentet skal i arkivet. Svaret
 * vurderes mot fasiten og lagres i arkivar_oppgavestatus. En registrering kan angres og gjøres på nytt.
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
        $ut = ['dokument' => Oppgavesett::dokument($oppgave), 'behandlet' => $status !== null];
        if ($status !== null) {
            $ut += $this->resultat($arkivId, $oppgave, $status);
        }

        return $ut;
    }

    /**
     * Behandler et dokument: validerer det studenten har fylt ut, oppretter sak og journalpost når dokumentet skal
     * i arkivet, og vurderer svaret mot fasiten. Gir det samme som vis() etterpå.
     *
     * @param array<string, mixed> $inn JSON-kroppen fra nettleseren: handling, sak og journalpost.
     * @return array<string, mixed>
     */
    public function registrer(string $arkivId, string $oppgaveId, array $inn): array
    {
        $oppgave = $this->oppgave($oppgaveId);
        if ($this->db->verdi('SELECT 1 FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]) !== null) {
            throw new HttpFeil(409, 'alt_behandlet', 'arkivar.feil.alt_behandlet');
        }
        $handling = Felt::tekst($inn['handling'] ?? null);
        if (!in_array($handling, Kodelister::HANDLINGER, true)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.handling');
        }

        return $this->db->transaksjon(function () use ($arkivId, $oppgave, $inn, $handling): array {
            $svar = self::tomtSvar($handling);
            $journalpostId = null;
            $sakOpprettet = false;
            if (in_array($handling, [Kodelister::JOURNALFOR, Kodelister::ARKIVER], true)) {
                [$svar, $journalpostId, $sakOpprettet] = $this->arkiver($arkivId, $oppgave, $inn, $handling);
            }
            $vurdering = Vurdering::vurder($oppgave, $svar, $this->grunndata);
            $this->db->utfor(
                'INSERT INTO arkivar_oppgavestatus (arkiv_id, oppgave_id, handling, journalpost_id, sak_opprettet, svar, vurdering, poeng, maks, behandlet) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $arkivId, $oppgave['id'], $handling, $journalpostId, $sakOpprettet ? 1 : 0,
                    json_encode($svar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($vurdering, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    count(array_filter($vurdering, static fn (array $k): bool => $k['ok'])), count($vurdering), Db::tid($this->klokke->now()),
                ],
            );

            return $this->vis($arkivId, $oppgave['id']);
        });
    }

    /** Angrer behandlingen: journalposten slettes (og saken, når den ble opprettet for dokumentet og er tom). */
    public function angre(string $arkivId, string $oppgaveId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $oppgaveId): void {
            $status = $this->db->en('SELECT * FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
            if ($status === null) {
                return;
            }
            $post = is_string($status['journalpost_id']) ? $this->register->journalpost($arkivId, $status['journalpost_id']) : null;
            if ($post !== null) {
                $this->register->slettJournalpost($arkivId, Felt::tekst($post['id']));
                if (Felt::tall($status['sak_opprettet']) === 1) {
                    $this->register->slettTomSak($arkivId, Felt::tekst($post['sak_id']));
                }
            }
            $this->db->utfor('DELETE FROM arkivar_oppgavestatus WHERE arkiv_id = ? AND oppgave_id = ?', [$arkivId, $oppgaveId]);
        });
    }

    /** @return Oppgave */
    private function oppgave(string $id): array
    {
        return $this->oppgaver->hent($id) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
    }

    /**
     * Oppretter (eller finner) saken og registrerer dokumentet i den.
     *
     * @param Oppgave $oppgave
     * @param array<string, mixed> $inn
     * @return array{0: Svar, 1: string, 2: bool} Svaret til vurderingen, journalpostens ID og om saken ble opprettet nå.
     */
    private function arkiver(string $arkivId, array $oppgave, array $inn, string $handling): array
    {
        $sakInn = is_array($inn['sak'] ?? null) ? $inn['sak'] : [];
        $postInn = is_array($inn['journalpost'] ?? null) ? $inn['journalpost'] : [];
        $idag = $this->klokke->now()->format('Y-m-d');
        $journalfor = $handling === Kodelister::JOURNALFOR;

        // Journalposten valideres før saken opprettes, så en feil ikke etterlater en tom sak.
        $tittel = self::tekst($postInn, 'tittel', 3, 500, 'arkivar.feil.tittel');
        $dokumentdato = Felt::tekst($postInn['dokumentdato'] ?? null);
        $dato = \DateTimeImmutable::createFromFormat('!Y-m-d', $dokumentdato);
        if ($dato === false || $dato->format('Y-m-d') !== $dokumentdato || $dokumentdato > $idag) {
            throw new HttpFeil(422, 'ugyldig_dato', 'arkivar.feil.dokumentdato');
        }
        $type = $journalfor ? Felt::tekst($postInn['type'] ?? null) : 'I';
        if (!in_array($type, Kodelister::JOURNALPOSTTYPER, true)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.journalposttype');
        }
        $part = trim(Felt::tekst($postInn['part_navn'] ?? null));
        if ($journalfor && in_array($type, ['I', 'U'], true) && $part === '') {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.part');
        }
        $tilgangskode = trim(Felt::tekst($postInn['tilgangskode'] ?? null));
        if ($tilgangskode !== '' && !$this->grunndata->harTilgangskode($tilgangskode)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.tilgangskode');
        }
        $skjermTittel = ($postInn['skjerm_tittel'] ?? false) === true;
        $skjermPart = ($postInn['skjerm_part'] ?? false) === true;
        if (($skjermTittel || $skjermPart) && $tilgangskode === '') {
            // Skjerming i journalen må ha en hjemmel, og hjemmelen følger tilgangskoden.
            throw new HttpFeil(422, 'skjerming_uten_hjemmel', 'arkivar.feil.skjerming_uten_hjemmel');
        }
        $offentligTittel = $skjermTittel ? trim(Felt::tekst($postInn['offentlig_tittel'] ?? null)) : '';

        $sakOpprettet = false;
        if (Felt::tekst($sakInn['type'] ?? null) === 'eksisterende') {
            $sak = $this->register->sak($arkivId, Felt::tekst($sakInn['id'] ?? null)) ?? throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.sak');
        } else {
            $sakstittel = self::tekst($sakInn, 'tittel', 3, 500, 'arkivar.feil.sakstittel');
            $arkivdel = Felt::tekst($sakInn['arkivdel'] ?? null);
            $klasse = Felt::tekst($sakInn['klasse'] ?? null);
            $enhet = Felt::tekst($sakInn['enhet'] ?? null);
            if (!$this->grunndata->harArkivdel($arkivdel)) {
                throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.arkivdel');
            }
            if (!$this->grunndata->kanKlassifiseres($klasse)) {
                throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.klasse');
            }
            if (!$this->grunndata->harEnhet($enhet)) {
                throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.enhet');
            }
            $skjermSak = ($sakInn['skjerm_tittel'] ?? false) === true;
            if ($skjermSak && $tilgangskode === '') {
                throw new HttpFeil(422, 'skjerming_uten_hjemmel', 'arkivar.feil.skjerming_uten_hjemmel');
            }
            $offentligSak = trim(Felt::tekst($sakInn['offentlig_tittel'] ?? null));
            $sakId = $this->register->opprettSak(
                $arkivId,
                $sakstittel,
                $arkivdel,
                $klasse,
                $enhet,
                $this->grunndata->forsteIEnhet($enhet),
                $idag,
                null,
                $skjermSak ? ($offentligSak !== '' ? $offentligSak : '*****') : null,
                $skjermSak ? $tilgangskode : null,
            );
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern');
            $sakOpprettet = true;
        }

        $journalpostId = $this->register->opprettJournalpost($arkivId, Felt::tekst($sak['id']), [
            'type' => $type,
            'status' => $journalfor ? 'J' : 'A',
            'tittel' => $tittel,
            'offentlig_tittel' => $skjermTittel ? ($offentligTittel !== '' ? $offentligTittel : '*****') : null,
            'dokumentdato' => $dokumentdato,
            'journaldato' => $idag,
            'saksbehandler' => Felt::tekst($sak['saksansvarlig']),
            'enhet' => Felt::tekst($sak['enhet']),
            'tilgangskode' => $tilgangskode !== '' ? $tilgangskode : null,
            'skjerm_tittel' => $skjermTittel,
            'skjerm_part' => $skjermPart,
            'part_navn' => $part,
            'part_adresse' => Felt::valgfriTekst($postInn['part_adresse'] ?? null),
            'journalfort' => $journalfor,
            'oppgave_id' => $oppgave['id'],
        ]);
        $svar = [
            'handling' => $handling,
            'sak_ny' => $sakOpprettet,
            'sak_nokkel' => Felt::valgfriTekst($sak['nokkel']),
            'sak_tekst' => Felt::tall($sak['saksaar']) . '/' . Felt::tall($sak['sekvens']) . ' ' . Felt::tekst($sak['tittel']),
            'sak_offentlig' => Felt::tall($sak['skjerm_tittel']) === 1 ? Felt::tekst($sak['offentlig_tittel'], '*****') : Felt::tekst($sak['tittel']),
            'arkivdel' => Felt::tekst($sak['arkivdel']),
            'klasse' => Felt::tekst($sak['klasse']),
            'type' => $type,
            'tittel' => $tittel,
            'offentlig_tittel' => $skjermTittel ? ($offentligTittel !== '' ? $offentligTittel : '*****') : null,
            'tilgangskode' => $tilgangskode !== '' ? $tilgangskode : null,
            'skjerm_tittel' => $skjermTittel,
            'skjerm_part' => $skjermPart,
            'dokumentdato' => $dokumentdato,
            'part_navn' => $part,
        ];

        return [$svar, $journalpostId, $sakOpprettet];
    }

    /**
     * Svaret når dokumentet ikke registreres i arkivet: bare behandlingen vurderes.
     *
     * @return Svar
     */
    private static function tomtSvar(string $handling): array
    {
        return [
            'handling' => $handling, 'sak_ny' => false, 'sak_nokkel' => null, 'sak_tekst' => '', 'sak_offentlig' => '', 'arkivdel' => '', 'klasse' => '', 'type' => '', 'tittel' => '',
            'offentlig_tittel' => null, 'tilgangskode' => null, 'skjerm_tittel' => false, 'skjerm_part' => false, 'dokumentdato' => '', 'part_navn' => '',
        ];
    }

    /**
     * En tekst fra skjemaet, uten mellomrom rundt. Kaster 422 med tekstnøkkelen når den er for kort eller for lang.
     *
     * @param array<array-key, mixed> $kilde
     */
    private static function tekst(array $kilde, string $felt, int $min, int $maks, string $feilnokkel): string
    {
        $verdi = trim(Felt::tekst($kilde[$felt] ?? null));
        $lengde = mb_strlen($verdi, 'UTF-8');
        if ($lengde < $min || $lengde > $maks) {
            throw new HttpFeil(422, 'ugyldig_felt', $feilnokkel);
        }

        return $verdi;
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
