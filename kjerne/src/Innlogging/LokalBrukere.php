<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Innlogging;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Krypto\Tilfeldig;

/**
 * De lokale driftsbrukerne for nødinnloggingen (BIV-AUT-04). De har bare tilgangen drift, aldri fagroller eller
 * brukeradministrasjon; vanlige brukere finnes bare i Bivrost Sentral.
 *
 * Brukernavnet normaliseres (NFKC, små bokstaver) og må passe et strengt mønster før det slås opp, og kolonnen har
 * binær kollasjon (utf8mb4_bin). Varianter med aksenter, fullbredde, mellomrom eller nullbreddetegn kan dermed ikke
 * gi et annet navn som databasen likevel regner som likt (vern mot omgåelse av grensen for forsøk).
 */
final class LokalBrukere
{
    public const MONSTER = '/^[a-z0-9][a-z0-9._-]{1,62}$/D';

    public function __construct(private readonly Db $db, private readonly Hemmelighetsboks $boks)
    {
    }

    /** Det normaliserte brukernavnet, eller null når det ikke passer mønsteret. */
    public static function normaliser(string $brukernavn): ?string
    {
        // Normalizer kommer fra intl eller fra symfony/polyfill-intl-normalizer, så resultatet er likt på alle servere.
        $x = \Normalizer::normalize($brukernavn, \Normalizer::FORM_KC);
        if (!is_string($x)) {
            return null;
        }
        $n = mb_strtolower(trim($x), 'UTF-8');

        return preg_match(self::MONSTER, $n) === 1 ? $n : null;
    }

    /** @return array<string, mixed>|null */
    public function finn(string $normalisertNavn): ?array
    {
        return $this->db->en('SELECT * FROM bivrost_lokal_bruker WHERE brukernavn = ?', [$normalisertNavn]);
    }

    /** @return array<string, mixed>|null */
    public function hent(string $id): ?array
    {
        return $this->db->en('SELECT * FROM bivrost_lokal_bruker WHERE id = ?', [$id]);
    }

    public function antallAktive(): int
    {
        return Felt::tall($this->db->verdi('SELECT COUNT(*) FROM bivrost_lokal_bruker WHERE aktiv = 1'));
    }

    /**
     * Lager en driftsbruker med passord og bekreftet TOTP-hemmelighet. Gir ID-en.
     */
    public function opprett(string $brukernavn, string $navn, ?string $tittel, ?string $epost, string $passordHash, string $totpHemmelighet, int $totpSteg): string
    {
        $n = self::normaliser($brukernavn);
        if ($n === null) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.innlogging.brukernavn');
        }
        if ($this->finn($n) !== null) {
            throw new HttpFeil(409, 'finnes', 'bivrost.feil.innlogging.brukernavn_finnes');
        }
        $id = Tilfeldig::uuid();
        $naa = Db::tid();
        $this->db->utfor(
            'INSERT INTO bivrost_lokal_bruker (id, brukernavn, navn, tittel, epost, passord_hash, totp_hemmelighet, totp_siste_steg, aktiv, generasjon, opprettet, endret, passord_endret)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?)',
            [$id, $n, $navn, $tittel, $epost, $passordHash, $this->boks->krypter($totpHemmelighet, 'totp:' . $id), $totpSteg, $naa, $naa, $naa],
        );

        return $id;
    }

    /**
     * TOTP-hemmeligheten i klartekst. Kaster BivrostFeil (bivrost.feil.krypto.*) når den ikke kan dekrypteres,
     * vanligvis fordi BIVROST_KRYPTERINGSNOKKEL er byttet; se totpLesbar().
     *
     * @param array<string, mixed> $bruker
     */
    public function totpHemmelighet(array $bruker): string
    {
        return $this->boks->dekrypter(Felt::tekst($bruker['totp_hemmelighet']), 'totp:' . Felt::tekst($bruker['id']));
    }

    /**
     * Om TOTP-hemmeligheten kan leses med krypteringsnøkkelen i .env. Er nøkkelen byttet, virker bare
     * gjenopprettingskodene (de er SHA-256, ikke kryptert), og brukeren må sette ny TOTP etter innloggingen.
     *
     * @param array<string, mixed> $bruker
     */
    public function totpLesbar(array $bruker): bool
    {
        try {
            $this->totpHemmelighet($bruker);

            return true;
        } catch (BivrostFeil) {
            return false;
        }
    }

    /**
     * Ny TOTP-hemmelighet for en driftsbruker, etter at brukeren har vist en kode fra den (LokalInnlogging::bekreftTotp).
     * Generasjonen øker, så alle andre økter for brukeren avsluttes (som ved passordbytte). $steg er tidssteget i
     * bekreftelsen, så den samme koden ikke kan brukes til å logge inn etterpå.
     */
    public function settTotp(string $id, string $hemmelighet, int $steg): void
    {
        $this->db->utfor(
            'UPDATE bivrost_lokal_bruker SET totp_hemmelighet = ?, totp_siste_steg = ?, endret = ?, generasjon = generasjon + 1 WHERE id = ?',
            [$this->boks->krypter($hemmelighet, 'totp:' . $id), $steg, Db::tid(), $id],
        );
    }

    /**
     * Krypterer en TOTP-hemmelighet som venter på bekreftelse, til økten (bivrost_okt.data er ikke kryptert). Formålet
     * er bundet til brukeren, så verdien ikke kan flyttes til en annen bruker eller til feltet totp_hemmelighet.
     */
    public function pakkInnVentendeTotp(string $id, string $hemmelighet): string
    {
        return $this->boks->krypter($hemmelighet, 'totp_ny:' . $id);
    }

    /** Den ventende TOTP-hemmeligheten fra økten, eller null når den ikke kan leses (f.eks. en annen bruker). */
    public function pakkUtVentendeTotp(string $id, string $kryptert): ?string
    {
        try {
            return $this->boks->dekrypter($kryptert, 'totp_ny:' . $id);
        } catch (BivrostFeil) {
            return null;
        }
    }

    /** Lagrer det brukte tidssteget bare når det er nyere enn det forrige (vern mot gjenbruk, også ved samtidige kall). */
    public function brukSteg(string $id, int $steg): bool
    {
        return $this->db->utfor(
            'UPDATE bivrost_lokal_bruker SET totp_siste_steg = ? WHERE id = ? AND (totp_siste_steg IS NULL OR totp_siste_steg < ?)',
            [$steg, $id, $steg],
        ) === 1;
    }

    public function settPassord(string $id, string $hash, bool $nyGenerasjon): void
    {
        $this->db->utfor(
            'UPDATE bivrost_lokal_bruker SET passord_hash = ?, passord_endret = ?, endret = ?' . ($nyGenerasjon ? ', generasjon = generasjon + 1' : '') . ' WHERE id = ?',
            [$hash, Db::tid(), Db::tid(), $id],
        );
    }

    /**
     * Ti nye gjenopprettingskoder (de gamle slutter å virke). Kodene vises bare én gang; bare SHA-256 lagres.
     *
     * @return list<string>
     */
    public function nyeGjenopprettingskoder(string $id): array
    {
        $koder = [];
        $this->db->transaksjon(function (Db $db) use ($id, &$koder): void {
            $db->utfor('DELETE FROM bivrost_gjenopprettingskode WHERE bruker_id = ?', [$id]);
            for ($i = 0; $i < 10; $i++) {
                $k = Tilfeldig::lesbarKode(16);
                $koder[] = $k;
                $db->utfor(
                    'INSERT INTO bivrost_gjenopprettingskode (bruker_id, kode_hash, laget) VALUES (?, ?, ?)',
                    [$id, hash('sha256', Tilfeldig::normaliserKode($k)), Db::tid()]
                );
            }
        });

        return $koder;
    }

    /** Bruker en gjenopprettingskode (bare én gang). */
    public function brukGjenopprettingskode(string $id, string $kode): bool
    {
        return $this->db->utfor(
            'UPDATE bivrost_gjenopprettingskode SET brukt = ? WHERE bruker_id = ? AND kode_hash = ? AND brukt IS NULL',
            [Db::tid(), $id, hash('sha256', Tilfeldig::normaliserKode($kode))],
        ) === 1;
    }

    public function gjenstaendeKoder(string $id): int
    {
        return Felt::tall($this->db->verdi('SELECT COUNT(*) FROM bivrost_gjenopprettingskode WHERE bruker_id = ? AND brukt IS NULL', [$id]));
    }
}
