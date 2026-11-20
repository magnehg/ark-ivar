<?php

declare(strict_types=1);

namespace ArkIvar\Brukere;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * Klassene (arkivar_klasse og arkivar_klassemedlem). En lærer oppretter en klasse og deler koden med studentene.
 * Koden gir rett til å registrere seg uten e-postadresse fra et godkjent sted, og den knytter studenten til
 * læreren, som da kan følge arbeidet.
 */
final class Klasser
{
    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke)
    {
    }

    public function opprett(string $navn, string $laererId): string
    {
        $id = Tilfeldig::uuid();
        $naa = Db::tid($this->klokke->now());
        $this->db->utfor(
            'INSERT INTO arkivar_klasse (id, navn, kode, laerer_id, aktiv, opprettet, endret) VALUES (?, ?, ?, ?, 1, ?, ?)',
            [$id, $navn, $this->ledigKode(), $laererId, $naa, $naa],
        );

        return $id;
    }

    /** En kode som ingen annen klasse har (åtte tegn som er lette å lese opp og skrive av). */
    private function ledigKode(): string
    {
        do {
            $kode = Tilfeldig::lesbarKode(8);
        } while ($this->db->verdi('SELECT 1 FROM arkivar_klasse WHERE kode = ?', [$kode]) !== null);

        return $kode;
    }

    /** @return array<string, mixed>|null */
    public function hent(string $id): ?array
    {
        return $this->db->en('SELECT * FROM arkivar_klasse WHERE id = ?', [$id]);
    }

    /**
     * Den aktive klassen med koden, slik studenten skrev den (med eller uten bindestrek, store eller små bokstaver).
     *
     * @return array<string, mixed>|null
     */
    public function medKode(string $kode): ?array
    {
        $ren = Tilfeldig::normaliserKode($kode);
        if (strlen($ren) !== 8) {
            return null;
        }

        return $this->db->en('SELECT * FROM arkivar_klasse WHERE kode = ? AND aktiv = 1', [implode('-', str_split($ren, 4))]);
    }

    /**
     * Klassene til en lærer, eller alle (for administratoren), med antall studenter som har bekreftet adressen.
     *
     * @return list<array<string, mixed>>
     */
    public function liste(?string $laererId = null): array
    {
        $sql = 'SELECT k.*, l.navn AS laerer_navn,
                       (SELECT COUNT(*) FROM arkivar_klassemedlem m JOIN arkivar_bruker b ON b.id = m.bruker_id
                         WHERE m.klasse_id = k.id AND b.epost_bekreftet IS NOT NULL) AS antall
                  FROM arkivar_klasse k LEFT JOIN arkivar_bruker l ON l.id = k.laerer_id';

        return $laererId === null
            ? $this->db->alle($sql . ' ORDER BY k.aktiv DESC, k.navn')
            : $this->db->alle($sql . ' WHERE k.laerer_id = ? ORDER BY k.aktiv DESC, k.navn', [$laererId]);
    }

    /** @return list<array<string, mixed>> */
    public function forStudent(string $brukerId): array
    {
        return $this->db->alle(
            'SELECT k.*, l.navn AS laerer_navn, m.innmeldt FROM arkivar_klassemedlem m
               JOIN arkivar_klasse k ON k.id = m.klasse_id LEFT JOIN arkivar_bruker l ON l.id = k.laerer_id
              WHERE m.bruker_id = ? ORDER BY k.navn',
            [$brukerId],
        );
    }

    /** Melder studenten inn. Gir false når studenten alt er med. */
    public function meldInn(string $klasseId, string $brukerId): bool
    {
        if ($this->db->verdi('SELECT 1 FROM arkivar_klassemedlem WHERE klasse_id = ? AND bruker_id = ?', [$klasseId, $brukerId]) !== null) {
            return false;
        }
        $this->db->utfor(
            'INSERT INTO arkivar_klassemedlem (klasse_id, bruker_id, innmeldt) VALUES (?, ?, ?)',
            [$klasseId, $brukerId, Db::tid($this->klokke->now())],
        );

        return true;
    }

    public function meldUt(string $klasseId, string $brukerId): void
    {
        $this->db->utfor('DELETE FROM arkivar_klassemedlem WHERE klasse_id = ? AND bruker_id = ?', [$klasseId, $brukerId]);
    }

    /**
     * Studentene i klassen som har bekreftet e-postadressen, sortert på navn.
     *
     * @return list<array<string, mixed>>
     */
    public function medlemmer(string $klasseId): array
    {
        return $this->db->alle(
            'SELECT b.*, m.innmeldt FROM arkivar_klassemedlem m JOIN arkivar_bruker b ON b.id = m.bruker_id
              WHERE m.klasse_id = ? AND b.epost_bekreftet IS NOT NULL ORDER BY b.navn, b.epost',
            [$klasseId],
        );
    }

    public function endre(string $id, string $navn, bool $aktiv): void
    {
        $this->db->utfor('UPDATE arkivar_klasse SET navn = ?, aktiv = ?, endret = ? WHERE id = ?', [$navn, $aktiv ? 1 : 0, Db::tid($this->klokke->now()), $id]);
    }

    /** Ny kode for klassen: den gamle slutter å virke (for eksempel når den er kommet på avveie). */
    public function nyKode(string $id): string
    {
        $kode = $this->ledigKode();
        $this->db->utfor('UPDATE arkivar_klasse SET kode = ?, endret = ? WHERE id = ?', [$kode, Db::tid($this->klokke->now()), $id]);

        return $kode;
    }

    /**
     * Klassen slik læreren og administratoren ser den (med koden).
     *
     * @param array<string, mixed> $rad
     * @return array<string, mixed>
     */
    public static function offentlig(array $rad, bool $medKode): array
    {
        $ut = [
            'id' => Felt::tekst($rad['id']),
            'navn' => Felt::tekst($rad['navn']),
            'aktiv' => Felt::tall($rad['aktiv']) === 1,
            'laerer' => Felt::valgfriTekst($rad['laerer_navn'] ?? null),
        ];
        if ($medKode) {
            $ut['kode'] = Felt::tekst($rad['kode']);
            $ut['laerer_id'] = Felt::tekst($rad['laerer_id']);
            $ut['antall'] = Felt::tall($rad['antall'] ?? 0);
        }

        return $ut;
    }
}
