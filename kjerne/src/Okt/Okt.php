<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Okt;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * Økter på serveren (SIK-03, ASVS V3, BIV-AUT-07). Økt-ID-en er 256 bit tilfeldig og står bare i
 * informasjonskapselen; databasen har SHA-256 av den, så en lekket database ikke gir gyldige økter.
 *
 *  - Kapselen er HttpOnly, SameSite=Lax (Lax trengs for at innloggingen fra Sentral kommer tilbake), Secure over
 *    HTTPS, og heter «__Host-bivrost» over HTTPS (bare denne verten, Path=/).
 *  - Ny ID ved innlogging og utlogging (roter()), så en økt ikke kan plantes (V3.2.1).
 *  - Tidsavbrudd ved inaktivitet og en maksimal levetid (V3.3.2), fra AppKonfig «okt».
 *  - Økter kan avsluttes på serveren for en bruker eller en OIDC-sid (back-channel logout, passordbytte).
 *  - CSRF-nøkkelen hører til økten (Csrf).
 * Uten innlogging lagres økten bare når noe er satt i den (f.eks. tilstanden under innloggingen).
 */
final class Okt
{
    private ?string $id = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $endret = false;
    private bool $slettKapsel = false;
    private int $laget;
    private ?string $gammelHash = null;

    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke,
        private readonly int $inaktivSekunder,
        private readonly int $maksSekunder,
        private readonly bool $https,
    ) {
        $this->laget = $this->naa();
    }

    public function kapselnavn(): string
    {
        return $this->https ? '__Host-bivrost' : 'bivrost';
    }

    private function naa(): int
    {
        return $this->klokke->now()->getTimestamp();
    }

    /** Leser økten fra informasjonskapselen. En ukjent, utløpt eller ugyldig økt gir en tom økt (og kapselen slettes). */
    public function start(Foresporsel $f): void
    {
        $id = $f->kapsel($this->kapselnavn());
        if ($id === null || preg_match('/^[A-Za-z0-9_-]{43}$/D', $id) !== 1) {
            if ($id !== null) {
                $this->slettKapsel = true;
            }

            return;
        }
        $r = $this->db->en('SELECT data, laget, sist_aktiv FROM bivrost_okt WHERE id_hash = ?', [hash('sha256', $id)]);
        $naa = $this->naa();
        if ($r === null) {
            $this->slettKapsel = true;

            return;
        }
        $laget = Felt::tall($r['laget']);
        $sist = Felt::tall($r['sist_aktiv']);
        if ($naa - $sist > $this->inaktivSekunder || $naa - $laget > $this->maksSekunder) {
            $this->db->utfor('DELETE FROM bivrost_okt WHERE id_hash = ?', [hash('sha256', $id)]);
            $this->slettKapsel = true;

            return;
        }
        $d = json_decode(Felt::tekst($r['data']), true);
        $this->id = $id;
        $this->laget = $laget;
        $this->data = [];
        foreach (is_array($d) ? $d : [] as $k => $v) {
            $this->data[(string) $k] = $v;
        }
        // Sist aktiv oppdateres høyst én gang i minuttet, så vanlige forespørsler ikke skriver hver gang.
        if ($naa - $sist >= 60) {
            $this->endret = true;
        }
    }

    public function hent(string $nokkel): mixed
    {
        return $this->data[$nokkel] ?? null;
    }

    public function sett(string $nokkel, mixed $verdi): void
    {
        $this->data[$nokkel] = $verdi;
        $this->endret = true;
    }

    public function fjern(string $nokkel): void
    {
        if (array_key_exists($nokkel, $this->data)) {
            unset($this->data[$nokkel]);
            $this->endret = true;
        }
    }

    public function innlogget(): ?Innlogget
    {
        $i = $this->data['innlogget'] ?? null;

        if (!is_array($i)) {
            return null;
        }
        /** @var array<string, mixed> $i */
        return Innlogget::fraListe($i);
    }

    /** Ny økt-ID (den gamle slettes), data beholdes. */
    public function roter(): void
    {
        if ($this->id !== null) {
            $this->gammelHash = hash('sha256', $this->id);
        }
        $this->id = null;
        $this->laget = $this->naa();
        $this->endret = true;
    }

    public function loggInn(Innlogget $i): void
    {
        $this->roter();
        // Bare det som hører til innloggingen, overlever: tilstand fra før (f.eks. OIDC-tilstanden) fjernes.
        $this->data = ['innlogget' => $i->tilListe(), 'csrf' => Tilfeldig::token()];
        $this->endret = true;
    }

    public function loggUt(): void
    {
        if ($this->id !== null) {
            $this->db->utfor('DELETE FROM bivrost_okt WHERE id_hash = ?', [hash('sha256', $this->id)]);
        }
        $this->id = null;
        $this->data = [];
        $this->endret = false;
        $this->slettKapsel = true;
    }

    /** Lagrer økten og setter informasjonskapselen på svaret. */
    public function lagre(Svar $svar): void
    {
        if ($this->gammelHash !== null) {
            $this->db->utfor('DELETE FROM bivrost_okt WHERE id_hash = ?', [$this->gammelHash]);
            $this->gammelHash = null;
        }
        if (!$this->endret || $this->data === []) {
            if ($this->slettKapsel) {
                $svar->settKapsel($this->kapselnavn(), '', 0, $this->https);
            }

            return;
        }
        $ny = $this->id === null;
        $this->id ??= Tilfeldig::token(32);
        $i = $this->innlogget();
        $naa = $this->naa();
        $this->db->utfor(
            'INSERT INTO bivrost_okt (id_hash, data, laget, sist_aktiv, aktor_type, aktor_id, oidc_sid)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), sist_aktiv = VALUES(sist_aktiv), aktor_type = VALUES(aktor_type),
                 aktor_id = VALUES(aktor_id), oidc_sid = VALUES(oidc_sid)',
            [
                hash('sha256', $this->id),
                json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->laget,
                $naa,
                $i?->aktor->type,
                $i?->aktor->id,
                $i?->sid,
            ],
        );
        $this->endret = false;
        if ($ny) {
            // Uten Max-Age: kapselen forsvinner når nettleseren lukkes; serveren håndhever levetiden.
            $svar->settKapsel($this->kapselnavn(), $this->id, -1, $this->https);
        }
    }

    /** Avslutter alle økter for en aktør (passordbytte, deaktivering). Gir antall. */
    public function avsluttFor(string $aktorType, string $aktorId): int
    {
        return $this->db->utfor('DELETE FROM bivrost_okt WHERE aktor_type = ? AND aktor_id = ?', [$aktorType, $aktorId]);
    }

    /** Avslutter øktene for en OIDC-sid eller en bruker (sub) fra Sentral (back-channel logout). */
    public function avsluttOidc(?string $sid, ?string $sub): int
    {
        $n = 0;
        if ($sid !== null) {
            $n += $this->db->utfor("DELETE FROM bivrost_okt WHERE aktor_type = 'bruker' AND oidc_sid = ?", [$sid]);
        }
        if ($sub !== null && $sid === null) {
            $n += $this->db->utfor("DELETE FROM bivrost_okt WHERE aktor_type = 'bruker' AND aktor_id = ?", [$sub]);
        }

        return $n;
    }

    /** Sletter utløpte økter. */
    public function rydd(): int
    {
        $naa = $this->naa();

        return $this->db->utfor('DELETE FROM bivrost_okt WHERE sist_aktiv < ? OR laget < ?', [$naa - $this->inaktivSekunder, $naa - $this->maksSekunder]);
    }
}
