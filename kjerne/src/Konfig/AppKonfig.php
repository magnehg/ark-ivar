<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Konfig;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Tilgang\Urn;

/**
 * Det appen forteller Bivrost Kjerne om seg selv (BIV-APP-01). Appen gir en matrise (vanligvis fra en PHP-fil som
 * returnerer den); Kjerne kontrollerer den her og kaster ved feil, så en feil i oppsettet oppdages ved første
 * forespørsel og ikke midt i en oppdatering.
 *
 * Nøkler (de med * kreves):
 *   app*            ID med små bokstaver a–z, tall og bindestrek (blir en del av URN-ene), f.eks. «gerd»
 *   appnavn*        navnet til visning på hovedspråket, f.eks. «Gerð»
 *   rot*            installasjonsroten (mappen som har bivrost/, appens mappe og drift/)
 *   drift           driftsmappen (sikkerhetskopier, oppdatering, låsefiler); standard <rot>/drift. Aldri under webroten.
 *   env             .env-filen; standard <rot>/.env
 *   webrot          mappen domenet peker på (til kontrollen av at drift/ og .env ikke kan nås fra nettet)
 *   hovedsprak      nb | nn | en (klarteksten i logg og eksport), standard nb
 *   roller          rolleregisteret: liste med ['navn' => 'arkivar', 'tekst' => ['nb' => 'Arkivar', …],
 *                   'beskrivelse' => ['nb' => …]] (appens fagroller, URN urn:bivrost:app:<app>:rolle:<navn>)
 *   separasjon      statisk separasjon av oppgaver (NIST RBAC SSD): liste med ['navn' => …, 'urner' => [...],
 *                   'maks' => 1] – en bruker kan ha høyst «maks» av URN-ene samtidig
 *   migreringer     appens migreringsmappe (NNN_navn.sql); null = ingen
 *   i18n            appens språkfiler (i18n/<språk>/<modul>.json, ICU MessageFormat), lagt over Bivrosts
 *   sprakpakker     mappen med språkpakker (standard <rot>/sprakpakker), se docs/sprakpakker.md
 *   tabellprefikser appens tabellprefikser (til sikkerhetskopien, som bare tar med installasjonens egne tabeller)
 *   pakke           appens versjonspakke: ['repo' => 'eier/repo', 'tagprefiks' => 'gerd-v', 'mappe' => 'app/']
 *   kjerne_krav     Bivrost Kjerne-versjonene den installerte appen virker med (semver, f.eks. «^0.1»)
 *   okt             ['inaktiv_minutter' => 30, 'maks_timer' => 12]
 *   logg            ['oppbevaring_dager' => 365, 'tabeller' => ['gerd_logg']]: oppbevaringen av sikkerhetsloggen
 *                   (revisjonsloggen slettes aldri) og appens loggtabeller (fagloggen i DbLoggLager-formen, med et
 *                   av appens tabellprefikser), som en tilbakerulling legger inn igjen som bivrost_logg (BIV-B-008)
 */
final class AppKonfig
{
    public readonly string $drift;
    public readonly string $envFil;
    public readonly ?string $webrot;
    public readonly string $hovedsprak;

    /** @var list<array{navn: string, tekst: array<string, string>, beskrivelse: array<string, string>}> */
    public readonly array $roller;

    /** @var list<array{navn: string, urner: list<string>, maks: int}> */
    public readonly array $separasjon;

    public readonly ?string $migreringer;

    /** Appens i18n-mappe (i18n/<språk>/<modul>.json) og mappen med språkpakker. */
    public readonly ?string $i18n;
    public readonly string $sprakpakker;

    /** @var list<string> */
    public readonly array $tabellprefikser;

    /** @var array{repo: string, tagprefiks: string, mappe: string}|null */
    public readonly ?array $pakke;

    public readonly string $kjerneKrav;
    public readonly int $oktInaktivMinutter;
    public readonly int $oktMaksTimer;
    public readonly int $loggOppbevaringDager;

    /**
     * Appens loggtabeller (DbLoggLager::tabellSql()). Hendelsene i dem etter et gjenopprettingspunkt overlever en
     * tilbakerulling, som hendelsene i bivrost_logg (BIV-B-008, BIV-LOG-05).
     *
     * @var list<string>
     */
    public readonly array $loggtabeller;

    /**
     * @param array<string, mixed> $k
     */
    public function __construct(
        public readonly string $app,
        public readonly string $appnavn,
        public readonly string $rot,
        array $k = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $app) !== 1 || $app === 'bivrost') {
            throw new BivrostFeil('bivrost.feil.konfig.app');
        }
        if (trim($appnavn) === '' || !is_dir($rot)) {
            throw new BivrostFeil('bivrost.feil.konfig.grunnlag');
        }
        $this->drift = rtrim(self::streng($k, 'drift') ?? $rot . '/drift', '/');
        $this->envFil = self::streng($k, 'env') ?? $rot . '/.env';
        $webrot = self::streng($k, 'webrot');
        $this->webrot = $webrot !== null ? rtrim($webrot, '/') : null;
        $sprak = self::streng($k, 'hovedsprak') ?? 'nb';
        if (!in_array($sprak, ['nb', 'nn', 'en'], true)) {
            throw new BivrostFeil('bivrost.feil.konfig.sprak');
        }
        $this->hovedsprak = $sprak;
        $this->roller = self::roller($k['roller'] ?? []);
        $this->separasjon = self::separasjon($k['separasjon'] ?? []);
        $this->migreringer = self::streng($k, 'migreringer');
        $this->i18n = self::streng($k, 'i18n');
        $this->sprakpakker = self::streng($k, 'sprakpakker') ?? $rot . '/sprakpakker';
        $pref = $k['tabellprefikser'] ?? [];
        if (!is_array($pref)) {
            throw new BivrostFeil('bivrost.feil.konfig.tabellprefikser');
        }
        $p = [];
        foreach ($pref as $x) {
            if (!is_string($x) || preg_match('/^[a-z][a-z0-9_]{1,30}_$/D', $x) !== 1 || $x === 'bivrost_') {
                throw new BivrostFeil('bivrost.feil.konfig.tabellprefikser');
            }
            $p[] = $x;
        }
        $this->tabellprefikser = $p;
        $this->pakke = self::pakke($k['pakke'] ?? null);
        $this->kjerneKrav = self::streng($k, 'kjerne_krav') ?? '*';
        $okt = is_array($k['okt'] ?? null) ? $k['okt'] : [];
        $this->oktInaktivMinutter = max(5, min(480, self::tall($okt, 'inaktiv_minutter', 30)));
        $this->oktMaksTimer = max(1, min(24, self::tall($okt, 'maks_timer', 12)));
        $logg = is_array($k['logg'] ?? null) ? $k['logg'] : [];
        $this->loggOppbevaringDager = max(30, self::tall($logg, 'oppbevaring_dager', 365));
        $this->loggtabeller = self::loggtabeller($logg['tabeller'] ?? [], $p);
        if ($this->webrot !== null && self::under($this->drift, $this->webrot)) {
            throw new BivrostFeil('bivrost.feil.konfig.drift_under_webrot');
        }
    }

    /**
     * Appens loggtabeller: gyldige tabellnavn med et av appens tabellprefikser, så de er med i sikkerhetskopien og
     * gjenopprettingspunktet og ikke kan være en av Bivrosts tabeller.
     *
     * @param list<string> $prefikser
     * @return list<string>
     */
    private static function loggtabeller(mixed $tabeller, array $prefikser): array
    {
        if (!is_array($tabeller)) {
            throw new BivrostFeil('bivrost.feil.konfig.loggtabeller');
        }
        $ut = [];
        foreach ($tabeller as $t) {
            $harPrefiks = false;
            foreach ($prefikser as $p) {
                $harPrefiks = $harPrefiks || (is_string($t) && str_starts_with($t, $p));
            }
            if (!is_string($t) || preg_match('/^[a-z][a-z0-9_]{0,60}$/D', $t) !== 1 || !$harPrefiks || in_array($t, $ut, true)) {
                throw new BivrostFeil('bivrost.feil.konfig.loggtabeller');
            }
            $ut[] = $t;
        }

        return $ut;
    }

    /** @param array<string, mixed> $k */
    public static function fraListe(array $k): self
    {
        $app = $k['app'] ?? null;
        $navn = $k['appnavn'] ?? null;
        $rot = $k['rot'] ?? null;
        if (!is_string($app) || !is_string($navn) || !is_string($rot)) {
            throw new BivrostFeil('bivrost.feil.konfig.grunnlag');
        }

        return new self($app, $navn, rtrim($rot, '/'), $k);
    }

    /** @param array<array-key, mixed> $k */
    private static function streng(array $k, string $n): ?string
    {
        $v = $k[$n] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** @param array<array-key, mixed> $k */
    private static function tall(array $k, string $n, int $standard): int
    {
        $v = $k[$n] ?? null;

        return is_int($v) ? $v : $standard;
    }

    private static function under(string $sti, string $mappe): bool
    {
        $s = Felt::ellers(realpath($sti), $sti);
        $m = Felt::ellers(realpath($mappe), $mappe);

        return $s === $m || str_starts_with($s . '/', rtrim($m, '/') . '/');
    }

    /**
     * @return array<string, string>
     */
    private static function tekster(mixed $t, bool $krevNb): array
    {
        if (is_string($t)) {
            $t = ['nb' => $t];
        }
        if (!is_array($t)) {
            throw new BivrostFeil('bivrost.feil.konfig.rolle');
        }
        $ut = [];
        foreach ($t as $sprak => $tekst) {
            if (!in_array($sprak, ['nb', 'nn', 'en'], true) || !is_string($tekst) || trim($tekst) === '') {
                throw new BivrostFeil('bivrost.feil.konfig.rolle');
            }
            $ut[$sprak] = $tekst;
        }
        if ($krevNb && !isset($ut['nb'])) {
            throw new BivrostFeil('bivrost.feil.konfig.rolle');
        }

        return $ut;
    }

    /** @return list<array{navn: string, tekst: array<string, string>, beskrivelse: array<string, string>}> */
    private static function roller(mixed $roller): array
    {
        if (!is_array($roller)) {
            throw new BivrostFeil('bivrost.feil.konfig.rolle');
        }
        $ut = [];
        $sett = [];
        foreach ($roller as $r) {
            if (!is_array($r) || !is_string($r['navn'] ?? null) || preg_match(Urn::NAVN, $r['navn']) !== 1) {
                throw new BivrostFeil('bivrost.feil.konfig.rolle');
            }
            if (isset($sett[$r['navn']])) {
                throw new BivrostFeil('bivrost.feil.konfig.rolle_dobbel', ['rolle' => $r['navn']]);
            }
            $sett[$r['navn']] = true;
            $ut[] = [
                'navn' => $r['navn'],
                'tekst' => self::tekster($r['tekst'] ?? null, true),
                'beskrivelse' => isset($r['beskrivelse']) ? self::tekster($r['beskrivelse'], false) : [],
            ];
        }

        return $ut;
    }

    /** @return list<array{navn: string, urner: list<string>, maks: int}> */
    private static function separasjon(mixed $s): array
    {
        if (!is_array($s)) {
            throw new BivrostFeil('bivrost.feil.konfig.separasjon');
        }
        $ut = [];
        foreach ($s as $r) {
            if (!is_array($r) || !is_string($r['navn'] ?? null) || !is_array($r['urner'] ?? null)) {
                throw new BivrostFeil('bivrost.feil.konfig.separasjon');
            }
            $urner = [];
            foreach ($r['urner'] as $u) {
                if (!is_string($u) || Urn::tolk($u) === null) {
                    throw new BivrostFeil('bivrost.feil.konfig.separasjon');
                }
                $urner[] = $u;
            }
            $maks = $r['maks'] ?? 1;
            if (!is_int($maks) || $maks < 1 || $maks >= count($urner)) {
                throw new BivrostFeil('bivrost.feil.konfig.separasjon');
            }
            $ut[] = ['navn' => $r['navn'], 'urner' => $urner, 'maks' => $maks];
        }

        return $ut;
    }

    /** @return array{repo: string, tagprefiks: string, mappe: string}|null */
    private static function pakke(mixed $p): ?array
    {
        if ($p === null) {
            return null;
        }
        if (
            !is_array($p) || !is_string($p['repo'] ?? null) || !is_string($p['tagprefiks'] ?? null)
            || !is_string($p['mappe'] ?? null)
            || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $p['repo']) !== 1
            || preg_match('/^[a-z][a-z0-9-]*-v$/D', $p['tagprefiks']) !== 1
            || preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*/$#D', $p['mappe']) !== 1
            || str_starts_with($p['mappe'], 'bivrost/')
        ) {
            throw new BivrostFeil('bivrost.feil.konfig.pakke');
        }

        return ['repo' => $p['repo'], 'tagprefiks' => $p['tagprefiks'], 'mappe' => $p['mappe']];
    }
}
