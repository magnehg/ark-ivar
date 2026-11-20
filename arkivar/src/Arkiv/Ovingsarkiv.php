<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * Øvingsarkivet til en bruker (arkivar_arkiv). Hver bruker har sitt eget arkiv for Sirevåg kommune. Det lages
 * første gang brukeren åpner arkivet, fylt med sakene fra grunndataene, og kan nullstilles: da slettes alt
 * brukeren har registrert, og arkivet fylles på nytt.
 */
final class Ovingsarkiv
{
    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke,
        private readonly Grunndata $grunndata,
        private readonly Register $register,
    ) {
    }

    /** ID-en til brukerens arkiv. Lager og fyller arkivet når brukeren ikke har noe. */
    public function forBruker(string $brukerId): string
    {
        $id = $this->db->verdi('SELECT id FROM arkivar_arkiv WHERE eier_id = ?', [$brukerId]);
        if (is_string($id)) {
            return $id;
        }

        return $this->db->transaksjon(function () use ($brukerId): string {
            $ny = Tilfeldig::uuid();
            $this->db->utfor('INSERT INTO arkivar_arkiv (id, eier_id, opprettet) VALUES (?, ?, ?)', [$ny, $brukerId, Db::tid($this->klokke->now())]);
            $this->fyll($ny);

            return $ny;
        });
    }

    /** Sletter alt i arkivet og fyller det på nytt med sakene fra grunndataene. */
    public function nullstill(string $arkivId): void
    {
        $this->db->transaksjon(function () use ($arkivId): void {
            if ($this->db->verdi('SELECT 1 FROM arkivar_arkiv WHERE id = ?', [$arkivId]) === null) {
                throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            }
            foreach (['arkivar_oppgavestatus', 'arkivar_journalpost', 'arkivar_sak'] as $tabell) {
                $this->db->utfor("DELETE FROM $tabell WHERE arkiv_id = ?", [$arkivId]);
            }
            $this->db->utfor('UPDATE arkivar_arkiv SET sak_sekvens = 0, journal_sekvens = 0, nullstilt = ? WHERE id = ?', [Db::tid($this->klokke->now()), $arkivId]);
            $this->fyll($arkivId);
        });
    }

    /**
     * Legger inn mappene og journalpostene som følger med arkivet. Mappene får saksnummer etter saksdatoen, og
     * journalpostene løpenummer etter journaldatoen på tvers av mappene, slik de ville fått i et arkiv i bruk.
     */
    private function fyll(string $arkivId): void
    {
        $saker = $this->grunndata->saker();
        usort($saker, static fn (array $a, array $b): int => [$a['saksdato'], $a['nokkel']] <=> [$b['saksdato'], $b['nokkel']]);
        $poster = [];
        foreach ($saker as $sak) {
            $sakId = $this->register->opprettSak(
                $arkivId,
                $sak['tittel'],
                $sak['arkivdel'],
                $sak['klasse'],
                $sak['enhet'],
                $sak['saksansvarlig'],
                $sak['saksdato'],
                $sak['nokkel'],
                $sak['offentlig_tittel'],
                $sak['tilgangskode'],
                $sak['status'],
                $sak['skjerm_klasse'],
            );
            foreach ($sak['journalposter'] as $post) {
                $poster[] = ['sak' => $sak, 'sak_id' => $sakId, 'post' => $post];
            }
        }
        usort($poster, static fn (array $a, array $b): int => [$a['post']['journaldato'], $a['sak']['saksdato'], $a['sak']['nokkel'], $a['post']['nr']]
            <=> [$b['post']['journaldato'], $b['sak']['saksdato'], $b['sak']['nokkel'], $b['post']['nr']]);
        foreach ($poster as ['sak' => $sak, 'sak_id' => $sakId, 'post' => $post]) {
            $this->register->opprettJournalpost($arkivId, $sakId, [
                'type' => $post['type'],
                'status' => $post['status'],
                'tittel' => $post['tittel'],
                'offentlig_tittel' => $post['offentlig_tittel'],
                'dokumentdato' => $post['dokumentdato'],
                'journaldato' => $post['journaldato'],
                'saksbehandler' => $sak['saksansvarlig'],
                'enhet' => $sak['enhet'],
                'tilgangskode' => $post['tilgangskode'],
                'skjerm_tittel' => $post['offentlig_tittel'] !== null,
                'skjerm_part' => $post['skjerm_part'],
                'part_navn' => $post['part'],
                'journalfort' => $post['journalfort'],
                'startdokument' => $post['dokument'] !== null ? $sak['nokkel'] . '#' . $post['nr'] : null,
            ]);
        }
    }

    /**
     * Tall til forsiden: saker, journalposter og hvor mange dokumenter i postmottaket som er behandlet.
     *
     * @return array{saker: int, journalposter: int, behandlet: int, poeng: int, maks: int}
     */
    public function oversikt(string $arkivId): array
    {
        $status = $this->db->en('SELECT COUNT(*) AS antall, COALESCE(SUM(poeng), 0) AS poeng, COALESCE(SUM(maks), 0) AS maks FROM arkivar_oppgavestatus WHERE arkiv_id = ?', [$arkivId]) ?? [];

        return [
            'saker' => Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_sak WHERE arkiv_id = ?', [$arkivId])),
            'journalposter' => Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_journalpost WHERE arkiv_id = ? AND journalfort = 1', [$arkivId])),
            'behandlet' => Felt::tall($status['antall'] ?? 0),
            'poeng' => Felt::tall($status['poeng'] ?? 0),
            'maks' => Felt::tall($status['maks'] ?? 0),
        ];
    }
}
