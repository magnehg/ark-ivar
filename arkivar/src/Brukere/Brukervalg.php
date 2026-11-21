<?php

declare(strict_types=1);

namespace ArkIvar\Brukere;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Psr\Clock\ClockInterface;

/**
 * Valgene en bruker har gjort i grensesnittet, lagret på kontoen (tabellen arkivar_brukervalg) så de følger
 * brukeren fra maskin til maskin. Bare valgene i VALG kan lagres, og bare med verdiene som står der; det første
 * er standardvalget for den som ikke har valgt.
 */
final class Brukervalg
{
    /** Hvordan oversiktene (mapper, dokumenter, journal og søketreff) vises: i kolonner, med tittelen først eller på to linjer. */
    public const LISTEVISNING = 'listevisning';

    /** @var array<string, list<string>> gyldige verdier per valg, standardverdien først */
    public const VALG = [
        self::LISTEVISNING => ['kolonner', 'tittel', 'linjer'],
    ];

    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke)
    {
    }

    /**
     * Alle valgene til brukeren, med standardverdien der brukeren ikke har valgt (eller har en verdi som ikke
     * lenger finnes).
     *
     * @return array<string, string>
     */
    public function hent(string $brukerId): array
    {
        $lagret = [];
        foreach ($this->db->alle('SELECT nokkel, verdi FROM arkivar_brukervalg WHERE bruker_id = ?', [$brukerId]) as $rad) {
            $lagret[Felt::tekst($rad['nokkel'])] = Felt::tekst($rad['verdi']);
        }
        $ut = [];
        foreach (self::VALG as $nokkel => $verdier) {
            $ut[$nokkel] = in_array($lagret[$nokkel] ?? null, $verdier, true) ? $lagret[$nokkel] : $verdier[0];
        }

        return $ut;
    }

    /** Om valget finnes og verdien er en av dem som kan lagres. */
    public static function gyldig(string $nokkel, string $verdi): bool
    {
        return in_array($verdi, self::VALG[$nokkel] ?? [], true);
    }

    /** Lagrer et valg. Verdien må være kontrollert med gyldig() først. */
    public function sett(string $brukerId, string $nokkel, string $verdi): void
    {
        $this->db->utfor(
            'INSERT INTO arkivar_brukervalg (bruker_id, nokkel, verdi, endret) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret = VALUES(endret)',
            [$brukerId, $nokkel, $verdi, Db::tid($this->klokke->now())],
        );
    }
}
