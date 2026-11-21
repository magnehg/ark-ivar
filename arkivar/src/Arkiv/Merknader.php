<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use ArkIvar\Brukere\Brukere;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * Merknader på en mappe eller en journalpost (arkivar_merknad): en kort tekst med hvem som skrev den, og når. En
 * merknad hører til enten mappen eller journalposten. Avskrivning uten svar kan ha en merknad som sier hvorfor
 * dokumentet ikke besvares skriftlig.
 *
 * @phpstan-type Merknad array{id: string, tekst: string, tidspunkt: ?string, av: string}
 */
final class Merknader
{
    public const MAKS_LENGDE = 2000;

    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke)
    {
    }

    /** Merknaden fra skjemaet, uten mellomrom rundt. Tom tekst er ingen merknad. For lang tekst avvises. */
    public static function les(mixed $verdi): string
    {
        $tekst = trim(Felt::tekst($verdi));
        if (!mb_check_encoding($tekst, 'UTF-8') || mb_strlen($tekst, 'UTF-8') > self::MAKS_LENGDE) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.merknad');
        }

        return $tekst;
    }

    /** Legger en merknad på journalposten. $brukerId er den som skrev den. */
    public function leggPaaJournalpost(string $arkivId, string $journalpostId, string $tekst, ?string $brukerId): void
    {
        $this->legg($arkivId, null, $journalpostId, $tekst, $brukerId);
    }

    /** Legger en merknad på mappen. $brukerId er den som skrev den. */
    public function leggPaaSak(string $arkivId, string $sakId, string $tekst, ?string $brukerId): void
    {
        $this->legg($arkivId, $sakId, null, $tekst, $brukerId);
    }

    /**
     * Merknadene på en journalpost, eldst først.
     *
     * @return list<Merknad>
     */
    public function forJournalpost(string $arkivId, string $journalpostId): array
    {
        return $this->hent('m.journalpost_id = ?', $arkivId, $journalpostId);
    }

    /**
     * Merknadene på en mappe, eldst først.
     *
     * @return list<Merknad>
     */
    public function forSak(string $arkivId, string $sakId): array
    {
        return $this->hent('m.sak_id = ?', $arkivId, $sakId);
    }

    private function legg(string $arkivId, ?string $sakId, ?string $journalpostId, string $tekst, ?string $brukerId): void
    {
        $this->db->utfor(
            'INSERT INTO arkivar_merknad (id, arkiv_id, sak_id, journalpost_id, tekst, bruker_id, opprettet) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Tilfeldig::uuid(), $arkivId, $sakId, $journalpostId, $tekst, $brukerId, Db::tid($this->klokke->now())],
        );
    }

    /** @return list<Merknad> */
    private function hent(string $vilkar, string $arkivId, string $id): array
    {
        $rader = $this->db->alle(
            "SELECT m.id, m.tekst, m.opprettet, b.navn FROM arkivar_merknad m LEFT JOIN arkivar_bruker b ON b.id = m.bruker_id
              WHERE m.arkiv_id = ? AND $vilkar ORDER BY m.opprettet, m.id",
            [$arkivId, $id],
        );

        return array_map(static fn (array $rad): array => [
            'id' => Felt::tekst($rad['id']), 'tekst' => Felt::tekst($rad['tekst']), 'tidspunkt' => Brukere::tidspunkt($rad['opprettet']), 'av' => Felt::tekst($rad['navn']),
        ], $rader);
    }
}
