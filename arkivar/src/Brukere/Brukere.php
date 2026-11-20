<?php

declare(strict_types=1);

namespace ArkIvar\Brukere;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Logg\Aktor;
use Bivrost\Kjerne\Okt\Appbrukere;
use Bivrost\Kjerne\Okt\Innlogget;
use Psr\Clock\ClockInterface;

/**
 * Brukerne i Ark-Ivar (studenter, lærere og administratorer) i tabellen arkivar_bruker. Ark-Ivar er ikke koblet til
 * Bivrost Sentral, så appen har brukerne selv, og Kjerne spør denne klassen ved hver forespørsel om en innlogging
 * fortsatt gjelder (Appbrukere).
 *
 * E-postadressen er brukernavnet. Den lagres med små bokstaver i en kolonne med binær kollasjon, så to adresser som
 * databasen ellers ville regnet som like (aksenter, store bokstaver), aldri blir samme konto.
 */
final class Brukere implements Appbrukere
{
    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke)
    {
    }

    /**
     * E-postadressen i den formen den lagres og slås opp i (små bokstaver, uten mellomrom rundt), eller null når den
     * ikke er en gyldig adresse. Bare ASCII godtas: adresser med andre tegn kan se like ut uten å være det.
     */
    public static function normaliserEpost(string $epost): ?string
    {
        $adresse = strtolower(trim($epost));
        if ($adresse === '' || strlen($adresse) > 254 || preg_match('/^[\x21-\x7e]+$/D', $adresse) !== 1) {
            return null;
        }

        return filter_var($adresse, FILTER_VALIDATE_EMAIL) === false ? null : $adresse;
    }

    /** Domenet i en normalisert e-postadresse (delen etter den siste @). */
    public static function domene(string $normalisertEpost): string
    {
        return substr($normalisertEpost, (int) strrpos($normalisertEpost, '@') + 1);
    }

    /** @return array<string, mixed>|null */
    public function finn(string $normalisertEpost): ?array
    {
        return $this->db->en('SELECT * FROM arkivar_bruker WHERE epost = ?', [$normalisertEpost]);
    }

    /** @return array<string, mixed>|null */
    public function hent(string $id): ?array
    {
        return $this->db->en('SELECT * FROM arkivar_bruker WHERE id = ?', [$id]);
    }

    /**
     * Lager en bruker og gir ID-en. $via sier hvordan brukeren kom inn (domene, klasse eller administrator), og
     * $bekreftet om e-postadressen alt er kontrollert (bare når en administrator har laget brukeren).
     */
    public function opprett(string $normalisertEpost, string $navn, string $passordHash, string $rolle, string $via, bool $bekreftet): string
    {
        $id = Tilfeldig::uuid();
        $naa = Db::tid($this->klokke->now());
        $this->db->utfor(
            'INSERT INTO arkivar_bruker (id, epost, navn, passord_hash, rolle, aktiv, generasjon, epost_bekreftet, registrert_via, opprettet, endret)
             VALUES (?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?)',
            [$id, $normalisertEpost, $navn, $passordHash, $rolle, $bekreftet ? $naa : null, $via, $naa, $naa],
        );

        return $id;
    }

    /** En registrering som ikke er bekreftet, kan gjøres på nytt: navnet og passordet fra det siste forsøket gjelder. */
    public function oppdaterUbekreftet(string $id, string $navn, string $passordHash, string $via): void
    {
        $this->db->utfor(
            'UPDATE arkivar_bruker SET navn = ?, passord_hash = ?, registrert_via = ?, endret = ? WHERE id = ? AND epost_bekreftet IS NULL',
            [$navn, $passordHash, $via, Db::tid($this->klokke->now()), $id],
        );
    }

    public function bekreftEpost(string $id): void
    {
        $naa = Db::tid($this->klokke->now());
        $this->db->utfor('UPDATE arkivar_bruker SET epost_bekreftet = ?, endret = ? WHERE id = ? AND epost_bekreftet IS NULL', [$naa, $naa, $id]);
    }

    /**
     * Nytt passord. Med $nyGenerasjon avsluttes alle eldre økter ved neste forespørsel (gyldig()); uten er det bare
     * hashen som er regnet ut på nytt med sterkere innstillinger.
     */
    public function settPassord(string $id, string $passordHash, bool $nyGenerasjon): void
    {
        $this->db->utfor(
            'UPDATE arkivar_bruker SET passord_hash = ?, generasjon = generasjon + ?, endret = ? WHERE id = ?',
            [$passordHash, $nyGenerasjon ? 1 : 0, Db::tid($this->klokke->now()), $id],
        );
    }

    public function settNavn(string $id, string $navn): void
    {
        $this->db->utfor('UPDATE arkivar_bruker SET navn = ?, endret = ? WHERE id = ?', [$navn, Db::tid($this->klokke->now()), $id]);
    }

    public function settRolle(string $id, string $rolle): void
    {
        $this->db->utfor('UPDATE arkivar_bruker SET rolle = ?, endret = ? WHERE id = ?', [$rolle, Db::tid($this->klokke->now()), $id]);
    }

    public function settAktiv(string $id, bool $aktiv): void
    {
        $this->db->utfor('UPDATE arkivar_bruker SET aktiv = ?, endret = ? WHERE id = ?', [$aktiv ? 1 : 0, Db::tid($this->klokke->now()), $id]);
    }

    public function registrerInnlogging(string $id): void
    {
        $this->db->utfor('UPDATE arkivar_bruker SET sist_innlogget = ? WHERE id = ?', [Db::tid($this->klokke->now()), $id]);
    }

    public function antallAktive(string $rolle): int
    {
        return Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_bruker WHERE rolle = ? AND aktiv = 1', [$rolle]));
    }

    /**
     * Brukerne til administrasjonen, sortert på navn. $sok finner i navn og e-postadresse.
     *
     * @return list<array<string, mixed>>
     */
    public function liste(?string $sok = null, int $maks = 500): array
    {
        $maks = max(1, min(1000, $maks));
        if ($sok === null || trim($sok) === '') {
            return $this->db->alle("SELECT * FROM arkivar_bruker ORDER BY navn, epost LIMIT $maks");
        }
        $monster = '%' . addcslashes(mb_strtolower(trim($sok), 'UTF-8'), '%_\\') . '%';

        return $this->db->alle(
            "SELECT * FROM arkivar_bruker WHERE LOWER(navn) LIKE ? OR epost LIKE ? ORDER BY navn, epost LIMIT $maks",
            [$monster, $monster],
        );
    }

    /**
     * Brukeren slik den sendes til nettleseren. Passordhashen er aldri med.
     *
     * @param array<string, mixed> $rad
     * @return array<string, mixed>
     */
    public static function offentlig(array $rad): array
    {
        return [
            'id' => Felt::tekst($rad['id']),
            'epost' => Felt::tekst($rad['epost']),
            'navn' => Felt::tekst($rad['navn']),
            'rolle' => Felt::tekst($rad['rolle']),
            'aktiv' => Felt::tall($rad['aktiv']) === 1,
            'epost_bekreftet' => $rad['epost_bekreftet'] !== null,
            'registrert_via' => Felt::tekst($rad['registrert_via']),
            'opprettet' => self::tidspunkt($rad['opprettet']),
            'sist_innlogget' => self::tidspunkt($rad['sist_innlogget']),
        ];
    }

    /** DATETIME(6) i UTC fra databasen som ISO 8601 med Z, eller null. */
    public static function tidspunkt(mixed $verdi): ?string
    {
        if (!is_string($verdi) || $verdi === '') {
            return null;
        }

        return str_replace(' ', 'T', substr($verdi, 0, 19)) . 'Z';
    }

    /**
     * Innloggingen som legges i økten: aktøren (navnet og rollen i klartekst, til loggen), URN-ene for rollen og
     * passordgenerasjonen.
     *
     * @param array<string, mixed> $rad
     */
    public function innlogget(array $rad, string $rolletekst): Innlogget
    {
        return new Innlogget(
            Aktor::bruker(Felt::tekst($rad['id']), Felt::tekst($rad['navn']), $rolletekst),
            Roller::urner(Felt::tekst($rad['rolle'])),
            'app',
            $this->klokke->now()->getTimestamp(),
            null,
            Felt::tall($rad['generasjon']),
            Felt::tekst($rad['epost']),
        );
    }

    /**
     * Økten gjelder bare så lenge brukeren finnes, er aktiv, har bekreftet e-postadressen, har samme
     * passordgenerasjon og har de samme rollene som da økten ble laget. En endret rolle logger altså brukeren ut,
     * og den nye rollen gjelder fra neste innlogging.
     */
    public function gyldig(Innlogget $innlogget): bool
    {
        $rad = $innlogget->aktor->id !== null ? $this->hent($innlogget->aktor->id) : null;
        if ($rad === null || Felt::tall($rad['aktiv']) !== 1 || $rad['epost_bekreftet'] === null) {
            return false;
        }

        return Felt::tall($rad['generasjon']) === $innlogget->generasjon
            && Roller::urner(Felt::tekst($rad['rolle'])) === $innlogget->urner;
    }
}
