<?php

declare(strict_types=1);

namespace ArkIvar\Brukere;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * De godkjente e-postdomenene (arkivar_domene). En student med e-postadresse fra et godkjent sted (for eksempel et
 * universitet) kan registrere seg selv. Administratoren styrer listen. Med «underdomener» gjelder domenet også
 * adresser under det, så uis.no dekker både ansatt@uis.no og student@stud.uis.no.
 */
final class Domener
{
    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke)
    {
    }

    /**
     * Domenet i den formen det lagres i (små bokstaver, uten @ foran og punktum bak), eller null når det ikke er et
     * gyldig domenenavn med minst to ledd.
     */
    public static function normaliser(string $domene): ?string
    {
        $navn = rtrim(ltrim(strtolower(trim($domene)), '@'), '.');
        $ledd = '[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?';
        if (strlen($navn) > 253 || preg_match('/^' . $ledd . '(\.' . $ledd . ')+$/D', $navn) !== 1) {
            return null;
        }

        return $navn;
    }

    /**
     * Det godkjente domenet e-postadressen hører til, eller null. Et eksakt treff går foran et overordnet domene.
     *
     * @return array<string, mixed>|null
     */
    public function godkjent(string $normalisertEpost): ?array
    {
        $domene = Brukere::domene($normalisertEpost);
        $ledd = explode('.', $domene);
        for ($fra = 0; $fra < count($ledd) - 1; $fra++) {
            $kandidat = implode('.', array_slice($ledd, $fra));
            $rad = $this->db->en('SELECT * FROM arkivar_domene WHERE domene = ? AND aktiv = 1', [$kandidat]);
            if ($rad !== null && ($fra === 0 || Felt::tall($rad['underdomener']) === 1)) {
                return $rad;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function liste(bool $bareAktive = false): array
    {
        return $this->db->alle('SELECT * FROM arkivar_domene' . ($bareAktive ? ' WHERE aktiv = 1' : '') . ' ORDER BY sted, domene');
    }

    /** @return array<string, mixed>|null */
    public function hent(string $id): ?array
    {
        return $this->db->en('SELECT * FROM arkivar_domene WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function finn(string $normalisertDomene): ?array
    {
        return $this->db->en('SELECT * FROM arkivar_domene WHERE domene = ?', [$normalisertDomene]);
    }

    public function opprett(string $normalisertDomene, string $sted, bool $underdomener): string
    {
        $id = Tilfeldig::uuid();
        $naa = Db::tid($this->klokke->now());
        $this->db->utfor(
            'INSERT INTO arkivar_domene (id, domene, sted, underdomener, aktiv, opprettet, endret) VALUES (?, ?, ?, ?, 1, ?, ?)',
            [$id, $normalisertDomene, $sted, $underdomener ? 1 : 0, $naa, $naa],
        );

        return $id;
    }

    public function endre(string $id, string $sted, bool $underdomener, bool $aktiv): void
    {
        $this->db->utfor(
            'UPDATE arkivar_domene SET sted = ?, underdomener = ?, aktiv = ?, endret = ? WHERE id = ?',
            [$sted, $underdomener ? 1 : 0, $aktiv ? 1 : 0, Db::tid($this->klokke->now()), $id],
        );
    }

    public function slett(string $id): void
    {
        $this->db->utfor('DELETE FROM arkivar_domene WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $rad
     * @return array<string, mixed>
     */
    public static function offentlig(array $rad): array
    {
        return [
            'id' => Felt::tekst($rad['id']),
            'domene' => Felt::tekst($rad['domene']),
            'sted' => Felt::tekst($rad['sted']),
            'underdomener' => Felt::tall($rad['underdomener']) === 1,
            'aktiv' => Felt::tall($rad['aktiv']) === 1,
        ];
    }
}
