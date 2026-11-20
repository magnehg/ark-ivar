<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Innlogging\Forsok;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Kontekst;
use Bivrost\Kjerne\Logg\Aktor;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggObjekt;

/**
 * Oppdaterings-API-et for maskiner (BIV-DRF-10), f.eks. GitHub Actions rett etter en ny versjon:
 *   GET  /bivrost/api/system/oppdatering/status   installerte versjoner, om noe venter, pågående steg
 *   POST /bivrost/api/system/oppdatering/kjor     neste steg (hentet | filer | database | ingenting)
 * med «Authorization: Bearer <nøkkel>» (RFC 6750). Nøkkelen lages på siden Oppdatering, vises én gang, og bare
 * SHA-256 lagres. Autentiseringen er samlet i autentiser(), så den kan byttes ut med OAuth 2.0 client credentials
 * (RFC 6749 § 4.4) og JWT-tilgangstokener (RFC 9068) fra Sentral uten å endre rutene.
 *
 * Vern (lærdom fra gjennomgangen): uten gyldig nøkkel svarer rutene 404 som om API-et ikke fantes; en riktig nøkkel
 * godtas alltid (256 bit kan ikke gjettes), mens feil nøkler telles per IP-adresse og gir en forsinkelse, så en
 * angriper ikke kan stenge ute den riktige; API-et legger aldri inn en eldre versjon; status gir ingen navn eller
 * interne feiltekster, bare koder.
 */
final class OppdateringsApi
{
    private const INNSTILLING = 'oppdatering.api';

    public function __construct(
        private readonly Db $db,
        private readonly Oppdaterer $oppdaterer,
        private readonly Forsok $forsok,
        private readonly Logg $logg,
        private readonly Kontekst $kontekst,
    ) {
    }

    /**
     * Lager en ny nøkkel (den gamle slutter å virke). Gir nøkkelen; den vises bare denne ene gangen.
     */
    public function lagNokkel(string $navn): string
    {
        $navn = trim($navn);
        if ($navn === '' || mb_strlen($navn, 'UTF-8') > 100 || preg_match('/[\x00-\x1F\x7F]/', $navn) === 1) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'navn']);
        }
        $nokkel = 'bivrost_' . Tilfeldig::token(32);
        $hvem = $this->kontekst->aktor();
        $this->db->utfor(
            'INSERT INTO bivrost_innstilling (nokkel, verdi, endret) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE verdi = VALUES(verdi), endret = VALUES(endret)',
            [self::INNSTILLING, json_encode(['hash' => hash('sha256', $nokkel), 'navn' => $navn, 'laget' => gmdate('c'), 'laget_av' => $hvem->navn], JSON_THROW_ON_ERROR), Db::tid()],
        );
        $this->logg->skriv('api:nokkel_laget', new LoggObjekt(null, 'api_nokkel', $this->logg->tekst('bivrost.objekttype.api_nokkel'), null, $navn));

        return $nokkel;
    }

    public function trekkTilbake(): void
    {
        $i = $this->info();
        $this->db->utfor('DELETE FROM bivrost_innstilling WHERE nokkel = ?', [self::INNSTILLING]);
        if ($i !== null) {
            $this->logg->skriv('api:nokkel_trukket', new LoggObjekt(null, 'api_nokkel', $this->logg->tekst('bivrost.objekttype.api_nokkel'), null, $i['navn']));
        }
    }

    /** @return array{hash: string, navn: string, laget: string, laget_av: string}|null */
    public function info(): ?array
    {
        $v = $this->db->verdi('SELECT verdi FROM bivrost_innstilling WHERE nokkel = ?', [self::INNSTILLING]);
        $j = is_string($v) ? json_decode($v, true) : null;
        if (!is_array($j) || !is_string($j['hash'] ?? null) || !is_string($j['navn'] ?? null)) {
            return null;
        }

        return ['hash' => $j['hash'], 'navn' => $j['navn'], 'laget' => is_string($j['laget'] ?? null) ? $j['laget'] : '',
            'laget_av' => is_string($j['laget_av'] ?? null) ? $j['laget_av'] : ''];
    }

    /** Svarer på API-rutene, eller null når stien ikke er en av dem. */
    public function handter(Foresporsel $f): ?Svar
    {
        $ruter = ['/bivrost/api/system/oppdatering/status' => 'GET', '/bivrost/api/system/oppdatering/kjor' => 'POST'];
        if (!isset($ruter[$f->sti])) {
            return null;
        }
        if (!$this->autentiser($f) || $ruter[$f->sti] !== $f->metode) {
            return Svar::json(['kode' => 'ikke_funnet'], 404);
        }
        $this->kontekst->settKilde('api');
        $this->kontekst->fortsettKorrelasjon($f->hode('x-bivrost-korrelasjon'));
        if ($f->metode === 'GET') {
            $s = $this->oppdaterer->status(true);
            $pakker = [];
            foreach (is_array($s['pakker'] ?? null) ? $s['pakker'] : [] as $p) {
                if (is_array($p)) {
                    $i = is_array($p['installert'] ?? null) ? $p['installert'] : [];
                    $pakker[] = ['id' => $p['id'] ?? null, 'versjon' => $i['versjon'] ?? null];
                }
            }

            return Svar::json([
                'pakker' => $pakker,
                'ny_versjon' => is_array($s['plan']) && $s['plan'] !== [],
                'pagaende' => is_array($s['pagaende']) ? ($s['pagaende']['steg'] ?? null) : null,
                'holdt_igjen' => $s['holdt_igjen'],
                'korrelasjon' => $this->kontekst->korrelasjon(),
            ]);
        }
        try {
            $r = $this->oppdaterer->kjor(true);
        } catch (HttpFeil $e) {
            // Ved github_feil følger statuskoden fra GitHub med, så den som kaller, kan skille 401, 403 og 404.
            $github = $e->kode === 'github_feil' && is_int($e->verdier['status'] ?? null) ? ['github_status' => $e->verdier['status']] : [];

            return Svar::json(['kode' => $e->kode, 'korrelasjon' => $this->kontekst->korrelasjon()] + $github, $e->status);
        } catch (BivrostFeil $e) {
            return Svar::json(['kode' => 'oppdatering_feilet', 'korrelasjon' => $this->kontekst->korrelasjon()], 500);
        }

        return Svar::json(['resultat' => $r['resultat'], 'korrelasjon' => $r['korrelasjon']]);
    }

    private function autentiser(Foresporsel $f): bool
    {
        $gitt = $f->bearer();
        $i = $this->info();
        if ($i !== null && $gitt !== null && hash_equals($i['hash'], hash('sha256', $gitt))) {
            $this->kontekst->settAktor(Aktor::maskin($this->logg->tekst('bivrost.aktor.oppdateringsnokkel', ['navn' => $i['navn']])));

            return true;
        }
        if ($gitt !== null) {
            // Feil nøkkel: telles per IP, gir en forsinkelse og logges (sikkerhetsloggen), men svaret er det samme.
            $mange = $this->forsok->antall('api', $f->ip, 900) >= 10;
            $this->forsok->registrer('api', $f->ip);
            if (!$mange) {
                $this->logg->skrivTrygt('api:avvist', null, ['ip' => $f->ip]);
            }
            usleep(random_int(200000, 500000));
        }

        return false;
    }
}
