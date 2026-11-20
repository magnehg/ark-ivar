<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sikkerhetskopi;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Mappen med sikkerhetskopier (drift/sikkerhetskopier, utenfor webroten, BIV-DRF-09). Bare navn Bivrost selv lager,
 * godtas (aldri en sti fra brukeren). Opprydding (L-011): minst de ti nyeste av hvert slag beholdes, og ingen kopi
 * som er yngre enn 14 dager, slettes. Gjenopprettingspunktene (punkt-*.json) knytter en databasekopi og en filkopi
 * sammen, så tilbakerullingen tar begge (L-012).
 */
final class Kopilager
{
    public const BEHOLD = 10;
    public const MINSTE_ALDER_DAGER = 14;
    public const MONSTER = [
        'db' => '/^db-\d{8}-\d{6}-[0-9a-f]{8}\.sql\.gz$/D',
        'filer' => '/^filer-\d{8}-\d{6}-[0-9a-f]{8}\.zip$/D',
        'punkt' => '/^punkt-\d{8}-\d{6}-[0-9a-f]{8}\.json$/D',
    ];

    public function __construct(private readonly string $mappe)
    {
    }

    public function mappe(): string
    {
        if (!is_dir($this->mappe) && !@mkdir($this->mappe, 0750, true) && !is_dir($this->mappe)) {
            throw new BivrostFeil('bivrost.feil.kopi.mappe');
        }
        Mappevern::sikre(dirname($this->mappe));
        Mappevern::sikre($this->mappe);

        return $this->mappe;
    }

    public function nyttNavn(string $slag, string $ending): string
    {
        return $slag . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . $ending;
    }

    public function sti(string $navn): string
    {
        $ok = false;
        foreach (self::MONSTER as $m) {
            $ok = $ok || preg_match($m, $navn) === 1;
        }
        if (!$ok) {
            throw new BivrostFeil('bivrost.feil.kopi.navn');
        }

        return $this->mappe() . '/' . $navn;
    }

    /** @return list<array{navn: string, slag: string, storrelse: int, laget: string}> Nyeste først. */
    public function liste(): array
    {
        $ut = [];
        foreach (is_dir($this->mappe) ? Felt::ellers(scandir($this->mappe), []) : [] as $n) {
            foreach (self::MONSTER as $slag => $m) {
                if (preg_match($m, $n) === 1 && is_file($this->mappe . '/' . $n)) {
                    $ut[] = ['navn' => $n, 'slag' => $slag, 'storrelse' => (int) filesize($this->mappe . '/' . $n),
                        'laget' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($this->mappe . '/' . $n))];
                }
            }
        }
        // Nyeste først: tidsstempelet står etter slaget (db-, filer-, punkt-).
        $tid = static fn (string $n): string => substr($n, (int) strpos($n, '-'));
        usort($ut, static fn (array $a, array $b): int => $tid($b['navn']) <=> $tid($a['navn']));

        return $ut;
    }

    /**
     * Sletter gamle kopier etter regelen. $vern: navn som aldri slettes nå (f.eks. kopien fra denne kjøringen).
     *
     * @param list<string> $vern
     * @return list<string> Navnene som ble slettet.
     */
    public function rydd(array $vern = []): array
    {
        $grense = time() - self::MINSTE_ALDER_DAGER * 86400;
        $slettet = [];
        foreach (array_keys(self::MONSTER) as $slag) {
            $av = array_values(array_filter($this->liste(), static fn (array $k): bool => $k['slag'] === $slag));
            foreach (array_slice($av, self::BEHOLD) as $k) {
                $sti = $this->mappe . '/' . $k['navn'];
                if (!in_array($k['navn'], $vern, true) && (int) filemtime($sti) < $grense && @unlink($sti)) {
                    $slettet[] = $k['navn'];
                }
            }
        }

        return $slettet;
    }
}
