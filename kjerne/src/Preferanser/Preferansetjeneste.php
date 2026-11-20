<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Preferanser;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;

/**
 * Preferansene i appen (BIV-PRF-02–06): rekkefølgen er app > felles > system.
 *
 *  - Felles preferanser kommer fra Sentral ved innlogging (claimet «bivrost_preferanser», med «oppdatert») og
 *    huskes lokalt i bivrost_preferanse, så utseendet ikke faller tilbake når Sentral er nede.
 *  - En endring av de felles lagres lokalt med en gang og sendes til Sentral; svarer ikke Sentral, venter den
 *    (synkronisert = 0) og sendes ved neste anledning. Den nyeste endringen vinner (tidsstempel).
 *  - Appens egne valg (overstyringer) lagres bare her.
 * Nøkkelen er brukerens faste ID hos Sentral (sub), eller «lokal:<id>» for nødinnloggingen.
 */
final class Preferansetjeneste
{
    /**
     * @param SentralPreferanser|null $sentral Null når innloggingen mot Sentral er av (bare nødinnlogging): da lagres
     *     de felles preferansene bare lokalt og blir stående som ikke synkronisert.
     */
    public function __construct(private readonly Db $db, private readonly ?SentralPreferanser $sentral = null)
    {
    }

    /**
     * @return array{felles: Preferanser, app: Preferanser, felles_oppdatert: ?string, synkronisert: bool}
     */
    public function lagret(string $bruker): array
    {
        $r = $this->db->en('SELECT felles, app, felles_oppdatert, synkronisert FROM bivrost_preferanse WHERE bruker = ?', [$bruker]);
        if ($r === null) {
            return ['felles' => Preferanser::tom(), 'app' => Preferanser::tom(), 'felles_oppdatert' => null, 'synkronisert' => true];
        }
        $les = static function (mixed $j): Preferanser {
            $d = json_decode(is_string($j) ? $j : '{}', true);

            return Preferanser::fraListe(is_array($d) ? $d : [], false);
        };

        return [
            'felles' => $les($r['felles']),
            'app' => $les($r['app']),
            'felles_oppdatert' => is_string($r['felles_oppdatert']) ? $r['felles_oppdatert'] : null,
            'synkronisert' => Felt::tall($r['synkronisert']) === 1,
        ];
    }

    /** De som gjelder: app > felles > system. */
    public function effektive(string $bruker): Preferanser
    {
        $l = $this->lagret($bruker);

        return $l['app']->over($l['felles']->over(Preferanser::standard()));
    }

    /**
     * Hvilket nivå hver verdi kommer fra (til visningen: «Felles for alle appene» / «Bare i denne appen»).
     *
     * @return array<string, string>
     */
    public function kilder(string $bruker): array
    {
        $l = $this->lagret($bruker);
        $ut = [];
        foreach (Preferanser::FELT as $f) {
            $ut[$f] = $l['app']->hent($f) !== null ? 'app' : ($l['felles']->hent($f) !== null ? 'felles' : 'system');
        }

        return $ut;
    }

    /**
     * Ved innlogging: preferansene fra Sentral. De lagres lokalt, unntatt når en lokal endring som ikke er sendt, er
     * nyere; da prøves den sendt nå.
     *
     * @param array<string, mixed> $claim Claimet «bivrost_preferanser» (med «oppdatert», ISO 8601) pluss locale/timezone.
     */
    public function fraInnlogging(string $bruker, array $claim, ?string $tilgangstoken): void
    {
        $oppdatert = is_string($claim['oppdatert'] ?? null) ? self::tid($claim['oppdatert']) : null;
        unset($claim['oppdatert']);
        $fraSentral = Preferanser::fraListe($claim, false);
        $l = $this->lagret($bruker);
        if (!$l['synkronisert'] && ($oppdatert === null || ($l['felles_oppdatert'] !== null && $l['felles_oppdatert'] > $oppdatert))) {
            if ($tilgangstoken !== null) {
                $this->send($bruker, $l['felles'], $tilgangstoken, $l['felles_oppdatert']);
            }

            return;
        }
        $this->skriv($bruker, $fraSentral, $l['app'], $oppdatert ?? Db::tid(), true);
    }

    /**
     * Endrer de felles preferansene: lagres lokalt og sendes til Sentral når det finnes et tilgangstoken.
     *
     * @param array<string, string|null> $endringer
     * @return bool Om endringen er lagret hos Sentral.
     */
    public function endreFelles(string $bruker, array $endringer, ?string $tilgangstoken): bool
    {
        $l = $this->lagret($bruker);
        $ny = $l['felles']->med($endringer);
        $tid = Db::tid();
        $this->skriv($bruker, $ny, $l['app'], $tid, false);

        return $tilgangstoken !== null && $this->send($bruker, $ny, $tilgangstoken, $tid);
    }

    /** @param array<string, string|null> $endringer */
    public function endreApp(string $bruker, array $endringer): void
    {
        $l = $this->lagret($bruker);
        $this->skriv($bruker, $l['felles'], $l['app']->med($endringer), $l['felles_oppdatert'] ?? Db::tid(), $l['synkronisert']);
    }

    private function send(string $bruker, Preferanser $felles, string $token, ?string $tid): bool
    {
        if ($this->sentral === null || !$this->sentral->lagre($token, $felles)) {
            return false;
        }
        $this->db->utfor('UPDATE bivrost_preferanse SET synkronisert = 1 WHERE bruker = ? AND (felles_oppdatert <=> ?)', [$bruker, $tid]);

        return true;
    }

    private function skriv(string $bruker, Preferanser $felles, Preferanser $app, string $oppdatert, bool $synkronisert): void
    {
        $this->db->utfor(
            'INSERT INTO bivrost_preferanse (bruker, felles, app, felles_oppdatert, synkronisert, endret) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE felles = VALUES(felles), app = VALUES(app), felles_oppdatert = VALUES(felles_oppdatert),
                 synkronisert = VALUES(synkronisert), endret = VALUES(endret)',
            [
                $bruker,
                json_encode((object) $felles->tilListe(), JSON_THROW_ON_ERROR),
                json_encode((object) $app->tilListe(), JSON_THROW_ON_ERROR),
                $oppdatert,
                $synkronisert ? 1 : 0,
                Db::tid(),
            ],
        );
    }

    private static function tid(string $iso): ?string
    {
        try {
            return Db::tid(new \DateTimeImmutable($iso));
        } catch (\Throwable) {
            return null;
        }
    }
}
