<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Klokke;
use Psr\Clock\ClockInterface;

/**
 * De klarerte utgivernøklene, én per pakke (BIV-DRF-14, BIV-B-034): tillit ved første bruk (TOFU).
 *
 * Utgiverens offentlige nøkkel følger med pakken (<mappe>signeringsnokkel.offentlig). Installasjonen lagrer den
 * første nøkkelen den ser, ved installasjonen eller ved den første oppdateringen som har den, i bivrost_innstilling
 * (oppdatering.utgivernokkel.<pakke>). Fra da av må hver versjonspakke være signert med den klarerte nøkkelen. En pakke
 * kan bare bytte nøkkelen når den selv er signert med den klarerte (Oppdaterer), eller når driften har satt den nye
 * nøkkelen i .env (<PAKKE>_OFFENTLIG_NOKKEL, som alltid går foran).
 *
 * Nøkkelen ligger i databasen, men et bytte går bare fremover: en tilbakerulling legger inn databasekopien fra
 * gjenopprettingspunktet, men beholder de klarerte nøklene slik de er (taVare() og leggTilbake()), så en nøkkel som er
 * byttet ut fordi den er lekket, aldri blir klarert igjen. En rad som ikke kan leses, gir en feil, aldri «ingen nøkkel»
 * (signaturkravet faller aldri bort stille).
 */
final class Utgivernokler
{
    private const PREFIKS = 'oppdatering.utgivernokkel.';
    private const MAKS_FIL = 1024;

    /** Hvor nøkkelen kom fra: installasjonen, de installerte filene (eldre installasjoner), en oppdatering, et bytte
     *  signert med den forrige nøkkelen, eller .env (driften). */
    public const KILDER = ['installasjon', 'installert', 'oppdatering', 'bytte', 'env'];

    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke = new Klokke(),
    ) {
    }

    /**
     * Den klarerte nøkkelen for pakken, eller null når ingen er klarert ennå.
     *
     * @return array{nokkel: string, kilde: string, versjon: ?string, klarert: string, fingeravtrykk: string}|null
     */
    public function hent(string $pakke): ?array
    {
        $v = $this->db->verdi('SELECT verdi FROM bivrost_innstilling WHERE nokkel = ?', [self::PREFIKS . $pakke]);
        if ($v === null) {
            return null;
        }
        $j = is_string($v) ? json_decode($v, true) : null;
        $nokkel = is_array($j) && is_string($j['nokkel'] ?? null) ? self::normaliser($j['nokkel']) : null;
        if (!is_array($j) || $nokkel === null || !in_array($j['kilde'] ?? null, self::KILDER, true)) {
            throw new BivrostFeil('bivrost.feil.oppdatering.utgivernokkel_lager');
        }

        return [
            'nokkel' => $nokkel,
            'kilde' => $j['kilde'],
            'versjon' => is_string($j['versjon'] ?? null) ? $j['versjon'] : null,
            'klarert' => is_string($j['klarert'] ?? null) ? $j['klarert'] : '',
            'fingeravtrykk' => self::fingeravtrykk($nokkel),
        ];
    }

    /** Lagrer (eller bytter) den klarerte nøkkelen. Kontrollen av at byttet er lov, gjøres av kalleren. */
    public function lagre(string $pakke, string $nokkel, string $kilde, ?string $versjon): void
    {
        $n = self::normaliser($nokkel);
        if ($n === null || !in_array($kilde, self::KILDER, true)) {
            throw new \InvalidArgumentException('Ugyldig utgivernøkkel eller kilde.');
        }
        $naa = $this->klokke->now();
        $verdi = ['nokkel' => $n, 'kilde' => $kilde, 'versjon' => $versjon, 'klarert' => $naa->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
        $this->db->utfor(
            'INSERT INTO bivrost_innstilling (nokkel, verdi, endret) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret = VALUES(endret)',
            [self::PREFIKS . $pakke, json_encode($verdi, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), Db::tid($naa)],
        );
    }

    /**
     * De klarerte nøklene som de står i databasen (radene uendret), til tilbakerullingen (BIV-B-034: et bytte går
     * bare fremover). Leses før databasekopien leses inn og legges tilbake med leggTilbake() etterpå.
     *
     * @return list<array{nokkel: string, verdi: string, endret: string}>
     */
    public function taVare(): array
    {
        $ut = [];
        foreach ($this->db->alle('SELECT nokkel, verdi, endret FROM bivrost_innstilling WHERE nokkel LIKE ?', [self::PREFIKS . '%']) as $r) {
            if (is_string($r['nokkel']) && str_starts_with($r['nokkel'], self::PREFIKS) && is_string($r['verdi'])) {
                $ut[] = ['nokkel' => $r['nokkel'], 'verdi' => $r['verdi'], 'endret' => is_string($r['endret']) ? $r['endret'] : Db::tid($this->klokke->now())];
            }
        }

        return $ut;
    }

    /**
     * Gjør de klarerte nøklene lik $rader (fra taVare()): rader som har kommet tilbake med databasekopien, men ikke
     * fantes før tilbakerullingen, fjernes, og de som fantes, skrives inn igjen.
     *
     * @param list<array{nokkel: string, verdi: string, endret: string}> $rader
     */
    public function leggTilbake(array $rader): void
    {
        $this->db->transaksjon(function () use ($rader): void {
            $beholdes = array_map(static fn (array $r): string => $r['nokkel'], $rader);
            foreach ($this->db->alle('SELECT nokkel FROM bivrost_innstilling WHERE nokkel LIKE ?', [self::PREFIKS . '%']) as $r) {
                if (is_string($r['nokkel']) && str_starts_with($r['nokkel'], self::PREFIKS) && !in_array($r['nokkel'], $beholdes, true)) {
                    $this->db->utfor('DELETE FROM bivrost_innstilling WHERE nokkel = ?', [$r['nokkel']]);
                }
            }
            foreach ($rader as $r) {
                $this->db->utfor(
                    'INSERT INTO bivrost_innstilling (nokkel, verdi, endret) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret = VALUES(endret)',
                    [$r['nokkel'], $r['verdi'], $r['endret']],
                );
            }
        });
    }

    /** Nøkkelen i kanonisk base64 (32 byte), eller null når teksten ikke er en Ed25519-nøkkel. */
    public static function normaliser(string $nokkel): ?string
    {
        $r = base64_decode(trim($nokkel), true);

        return $r !== false && strlen($r) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? base64_encode($r) : null;
    }

    /**
     * Leser en nøkkelfil. null når filen ikke finnes; feil når den finnes, men ikke er en nøkkel (en ødelagt fil skal
     * ikke se ut som «ingen nøkkel»). $pakke og $visning (stien som vises, f.eks. «bivrost/kjerne/signeringsnokkel.offentlig»
     * eller «<tag>.zip:<sti>» for filen i en versjonspakke) står i feilen, så driften ser hvilken fil det gjelder.
     */
    public static function lesFil(string $fil, string $pakke, string $visning): ?string
    {
        if (!is_file($fil)) {
            return null;
        }
        $innhold = @file_get_contents($fil, false, null, 0, self::MAKS_FIL + 1);
        $n = is_string($innhold) && strlen($innhold) <= self::MAKS_FIL ? self::normaliser($innhold) : null;
        if ($n === null) {
            throw new BivrostFeil('bivrost.feil.oppdatering.nokkelfil', ['pakke' => $pakke, 'fil' => $visning]);
        }

        return $n;
    }

    /** Nøkkelfilen i en versjonspakke (zip), uten å pakke ut noe. null når pakken ikke har en gyldig nøkkelfil. */
    public static function iZip(string $zip, Pakke $p): ?string
    {
        $z = new \ZipArchive();
        if ($z->open($zip, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            $st = $z->statName($p->nokkelfil());
            $innhold = is_array($st) && $st['size'] <= self::MAKS_FIL ? $z->getFromName($p->nokkelfil(), self::MAKS_FIL) : false;
        } finally {
            $z->close();
        }

        return is_string($innhold) ? self::normaliser($innhold) : null;
    }

    /** Fingeravtrykket som vises og logges: «SHA256:» og base64 uten utfylling av SHA-256 over nøkkelen (som OpenSSH). */
    public static function fingeravtrykk(string $nokkel): string
    {
        $r = base64_decode(trim($nokkel), true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $r === false ? $nokkel : $r, true)), '=');
    }
}
