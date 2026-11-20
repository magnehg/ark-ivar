<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Klokke;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Logg\Verdi;
use Bivrost\Kjerne\Migrering\Migrator;
use Bivrost\Kjerne\Sikkerhetskopi\Databasekopi;
use Bivrost\Kjerne\Sikkerhetskopi\Filkopi;
use Bivrost\Kjerne\Sikkerhetskopi\Kopilager;
use Bivrost\Vendor\Composer\Semver\Semver;
use Psr\Clock\ClockInterface;

/**
 * Oppdatering fra GitHub for flere pakker (Bivrost Kjerne og appen), DRF-08–DRF-14, BIV-DRF-05–08.
 *
 * kjor() gjør ett steg per kall, så hvert kall holder seg under max_execution_time på et webhotell (30 s), og så
 * migreringene kjøres av den nye koden i et nytt kall:
 *
 *   1. hent      én pakke per kall: last ned, kontroller sjekksum (og GitHubs digest), signatur, versjon.json,
 *                manifest og kompatibilitet, og pakk ut i arbeidsmappen. Signaturen kreves når installasjonen har en
 *                utgivernøkkel: nøkkelen i .env, ellers den klarerte (Utgivernokler, tillit ved første bruk). En pakke
 *                som bytter nøkkelen, må være signert med den klarerte; en pakke med den første nøkkelen må være
 *                signert med den (BIV-DRF-07, BIV-DRF-14)
 *   2. filer     sikkerhetskopi av databasen og pakkenes mapper (et gjenopprettingspunkt), så legges alle pakkene
 *                inn samlet: bare endrede filer, versjon.json og manifest sist, filer som ikke lenger er med,
 *                fjernes; ved feil legges alle filene tilbake fra kopien. En ny eller byttet utgivernøkkel klareres
 *                først når filene er lagt inn
 *   3. database  migreringene for hver pakke (Kjerne først), med en ny databasekopi under migreringslåsen
 *
 * Planen velger de nyeste versjonene som passer sammen: hver pakke sier i versjon.json hva den krever
 * («krever»: {"php": ">=8.2", "bivrost-kjerne": "^0.1"}), og en kombinasjon som ikke er støttet, legges aldri inn.
 * Eldre versjoner legges aldri inn (gå tilbake med en sikkerhetskopi i stedet, Tilbakerulling).
 *
 * Mellomstopp (BIV-DRF-16, BIV-B-036): en versjon kan si i versjon.json hvilke installerte versjoner av samme pakke
 * den kan oppdateres fra («oppdateres_fra»: ">=1.4.0"). Ligger installasjonen lavere, velger planen den nyeste
 * versjonen den kan gå til (et mellomstopp), og neste plan, laget av koden i stoppet, går videre. Driften starter
 * oppdateringen én gang; stegene fortsetter til resultatet er «ingenting». Mangler feltet, kan versjonen oppdateres
 * fra alle eldre versjoner.
 * Tilstanden mellom kallene står i drift/oppdatering/tilstand.json; en fillås hindrer samtidige kjøringer.
 */
final class Oppdaterer
{
    private const MAKS_ZIP = 200 * 1024 * 1024;
    private const MAKS_LITEN = 64 * 1024;
    private const KANDIDATER = 5;

    /**
     * Høyst så mange versjoner per pakke lastes versjon.json ned for når planen leter etter et mellomstopp under den
     * nyeste. Grensen holder statusen og det første steget raske for en installasjon som ligger langt bak.
     */
    private const MAKS_UNDERSOKT = 30;

    /** @var list<string> Satt av plan(): pakker som har nyere versjoner, men ingen den installerte kan oppdateres til. */
    private array $utenVei = [];

    /** Satt av plan(): det finnes nyere versjoner, men ingen kombinasjon av dem virker sammen her. */
    private bool $nyereUkompatible = false;

    /**
     * @param list<Pakke> $pakker Kjerne først.
     * @param array<string, string> $nokler Offentlig signaturnøkkel fra .env per pakke-ID. Går foran den klarerte
     *                                     nøkkelen (tom = den klarerte, eller ingen).
     * @param callable(Pakke): Migrator $migrator
     */
    public function __construct(
        private readonly string $rot,
        private readonly string $drift,
        private readonly array $pakker,
        private readonly Utgivelseskilde $kilde,
        private readonly array $nokler,
        private readonly Utgivernokler $utgivere,
        private readonly bool $forhandsversjoner,
        private readonly Logg $logg,
        private readonly Databasekopi $dbKopi,
        private readonly Filkopi $filKopi,
        private readonly Kopilager $lager,
        private $migrator,
        private readonly ClockInterface $klokke = new Klokke(),
    ) {
    }

    /** Tiden etter Kjernens klokke, den samme som loggen bruker (tilbakerullingen sammenligner dem). */
    private function naa(string $format): string
    {
        return $this->klokke->now()->setTimezone(new \DateTimeZone('UTC'))->format($format);
    }

    private function mappe(): string
    {
        $m = $this->drift . '/oppdatering';
        if (!is_dir($m) && !@mkdir($m, 0750, true) && !is_dir($m)) {
            throw new BivrostFeil('bivrost.feil.oppdatering.arbeid');
        }

        return $m;
    }

    /** @return array<string, mixed>|null Versjon.json for den installerte pakken. */
    public function installert(Pakke $p): ?array
    {
        $f = $this->rot . '/' . $p->versjonsfil();
        if (!is_file($f)) {
            return null;
        }
        try {
            return Pakkeleser::json($f);
        } catch (BivrostFeil) {
            return null;
        }
    }

    private function installertVersjon(Pakke $p): string
    {
        $v = $this->installert($p)['versjon'] ?? null;

        return is_string($v) ? $v : '0.0.0';
    }

    public function holdtIgjen(): bool
    {
        return is_file($this->mappe() . '/holdt-igjen.json');
    }

    public function holdIgjen(bool $paa): void
    {
        $f = $this->mappe() . '/holdt-igjen.json';
        if ($paa) {
            file_put_contents($f, json_encode(['siden' => $this->naa(DATE_ATOM)], JSON_THROW_ON_ERROR));
        } elseif (is_file($f)) {
            unlink($f);
        }
    }

    /** @return array<string, mixed>|null */
    private function tilstand(): ?array
    {
        $f = $this->mappe() . '/tilstand.json';
        if (!is_file($f)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($f), true);
        if (!is_array($j)) {
            return null;
        }
        $ut = [];
        foreach ($j as $k => $v) {
            $ut[(string) $k] = $v;
        }

        return $ut;
    }

    /** @param array<string, mixed>|null $t */
    private function lagreTilstand(?array $t): void
    {
        $f = $this->mappe() . '/tilstand.json';
        if ($t === null) {
            @unlink($f);

            return;
        }
        $tmp = $f . '.ny';
        file_put_contents($tmp, json_encode($t, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $f);
    }

    /**
     * Status for alle pakkene, uten å endre noe. $sjekkGithub = false bruker ikke nettet.
     *
     * @return array<string, mixed>
     */
    public function status(bool $sjekkGithub = true): array
    {
        $pakker = [];
        foreach ($this->pakker as $p) {
            $m = ($this->migrator)($p)->status();
            $i = $this->installert($p);
            $nokkel = $this->nokkelstatus($p);
            $pakker[] = [
                'id' => $p->id,
                'navn' => $p->navn,
                'installert' => $i === null ? null : ['versjon' => $i['versjon'] ?? null, 'commit' => $i['commit'] ?? null, 'dato' => $i['dato'] ?? null],
                'signatur_kreves' => $nokkel['kilde'] !== null || $nokkel['feil'] !== null,
                'utgivernokkel' => $nokkel,
                'skjema' => ['versjon' => $m['versjon'], 'siste' => $m['siste'], 'ventende' => count($m['ventende']), 'feil' => $m['feil']],
            ];
        }
        $ut = [
            'pakker' => $pakker, 'holdt_igjen' => $this->holdtIgjen(), 'pagaende' => null, 'plan' => [], 'plan_feil' => null,
            'plan_feil_verdier' => null, 'nyere_ukompatible' => false, 'uten_vei' => [],
        ];
        $t = $this->tilstand();
        if ($t !== null) {
            $ut['pagaende'] = ['steg' => $t['steg'] ?? null, 'korrelasjon' => $t['korrelasjon'] ?? null, 'plan' => $t['plan'] ?? []];
        }
        if ($sjekkGithub) {
            try {
                $ut['plan'] = array_map(static fn (array $x): array => array_diff_key($x, ['utgivelse' => true]), $this->plan());
                $ut['nyere_ukompatible'] = $this->nyereUkompatible;
                $ut['uten_vei'] = $this->utenVei;
            } catch (BivrostFeil $e) {
                // Verdiene (f.eks. statuskoden fra GitHub) følger med, så teksten kan vises med dem (BIV-DRF-05).
                $ut['plan_feil'] = $e->tekstnokkel;
                $ut['plan_feil_verdier'] = $e->verdier === [] ? null : $e->verdier;
            }
        }

        return $ut;
    }

    /**
     * De nyeste versjonene som passer sammen og med PHP, og som de installerte versjonene kan oppdateres til
     * («oppdateres_fra»). «mellomstopp» er true når pakken har en nyere versjon enn den som velges; da kan flere
     * oppdateringer følge (etter et mellomstopp, eller når en annen pakke er oppdatert).
     * Tom liste = ingenting å oppdatere.
     *
     * @return list<array{pakke: string, fra: string, til: string, tag: string, mellomstopp: bool, utgivelse: Utgivelse}>
     */
    public function plan(): array
    {
        $valg = [];
        $nyere = [];
        $nedenfra = [];
        $this->utenVei = [];
        foreach ($this->pakker as $p) {
            $fra = $this->installertVersjon($p);
            $nyere[$p->id] = [];
            foreach ($this->kilde->utgivelser($p) as $u) {
                if (($u->forhandsversjon && !$this->forhandsversjoner) || version_compare($u->versjon, $fra, '<=')) {
                    continue;
                }
                if (!$u->har($u->zip()) || !$u->har($u->zip() . '.sha256') || !$u->har($u->tag . '.versjon.json')) {
                    continue;
                }
                $nyere[$p->id][] = $u;
            }
            $nedenfra[$p->id] = $nyere[$p->id] !== [] && !$this->kanOppdateresFra($p, $nyere[$p->id][0], $fra);
            $valg[$p->id] = $nedenfra[$p->id]
                ? $this->stopp($p, $fra, $nyere[$p->id])
                : array_slice($nyere[$p->id], 0, self::KANDIDATER);
        }
        $plan = $this->kombinasjon($valg, $nyere);
        // Kom en pakke som har nyere versjoner, ikke med, kan et stopp lenger ned være veien videre: den nyeste kunne
        // nås, men passet ikke (f.eks. med appens krever), og versjonene i mellom kan ikke nås. Let da nedenfra for
        // den pakken og prøv igjen, men bare når minst én av versjonene som er prøvd, ikke kunne nås. Uten
        // mellomstopp (f.eks. når appen venter på en nyere Kjerne) lastes ingenting ekstra ned.
        $igjen = false;
        foreach ($this->pakker as $p) {
            $med = $plan !== null && in_array($p->id, array_column($plan, 'pakke'), true);
            if ($nyere[$p->id] !== [] && !$med && !$nedenfra[$p->id] && $this->harUtilgjengelig($p, $valg[$p->id])) {
                $ekstra = array_filter($this->stopp($p, $this->installertVersjon($p), $nyere[$p->id]), static fn (Utgivelse $u): bool => !in_array($u, $valg[$p->id], true));
                if ($ekstra !== []) {
                    $valg[$p->id] = array_merge($valg[$p->id], $ekstra);
                    usort($valg[$p->id], static fn (Utgivelse $a, Utgivelse $b): int => version_compare($b->versjon, $a->versjon));
                    $igjen = true;
                }
            }
        }
        if ($igjen) {
            $plan = $this->kombinasjon($valg, $nyere);
        }
        foreach ($this->pakker as $p) {
            if ($nyere[$p->id] !== [] && $valg[$p->id] === []) {
                $this->utenVei[] = $p->id;
            }
        }
        if ($plan === null) {
            throw new BivrostFeil('bivrost.feil.oppdatering.ingen_kompatibel');
        }
        $this->nyereUkompatible = $plan === [] && $this->harNyere($valg);

        return $plan;
    }

    /**
     * Den første kombinasjonen, nyeste først, der alle «krever» passer og hver versjon kan nås fra den installerte.
     * null = ingen kombinasjon passer (heller ikke å bli stående).
     *
     * @param array<string, list<Utgivelse>> $valg Kandidatene per pakke, nyeste først (uten «bli stående»).
     * @param array<string, list<Utgivelse>> $nyere Alle nyere versjoner per pakke, nyeste først.
     * @return list<array{pakke: string, fra: string, til: string, tag: string, mellomstopp: bool, utgivelse: Utgivelse}>|null
     */
    private function kombinasjon(array $valg, array $nyere): ?array
    {
        $liste = [];
        foreach ($this->pakker as $p) {
            $liste[] = [...$valg[$p->id], null]; // null = bli på installert versjon
        }
        foreach ($this->kombinasjoner($liste) as $komb) {
            $mal = [];
            $info = [];
            foreach ($this->pakker as $i => $p) {
                $u = $komb[$i];
                if ($u === null) {
                    $mal[$p->id] = $this->installertVersjon($p);
                    $info[$p->id] = $this->installert($p) ?? [];
                } else {
                    $mal[$p->id] = $u->versjon;
                    $info[$p->id] = $this->info($p, $u);
                }
            }
            if ($this->passer($mal, $info) && $this->kanNas($komb)) {
                $plan = [];
                foreach ($this->pakker as $i => $p) {
                    $u = $komb[$i];
                    if ($u !== null) {
                        $plan[] = [
                            'pakke' => $p->id, 'fra' => $this->installertVersjon($p), 'til' => $u->versjon, 'tag' => $u->tag,
                            'mellomstopp' => $u !== ($nyere[$p->id][0] ?? null), 'utgivelse' => $u,
                        ];
                    }
                }

                return $plan;
            }
        }

        return null;
    }

    /**
     * Mellomstopp: alle versjonene, nyeste først, som den installerte versjonen $fra kan oppdateres til, blant de
     * MAKS_UNDERSOKT laveste nyere. Letingen går nedenfra: et stopp ligger nær den installerte versjonen, så det blir
     * funnet også når det har kommet mange versjoner etter det. Alle som kan nås, prøves (ikke bare de fem høyeste),
     * så en lavere versjon som passer med de andre pakkene, også blir valgt. Planen går til det høyeste stoppet som
     * passer, og neste plan går videre.
     *
     * @param list<Utgivelse> $nyere Nyeste først.
     * @return list<Utgivelse>
     */
    private function stopp(Pakke $p, string $fra, array $nyere): array
    {
        $kan = [];
        foreach (array_slice(array_reverse($nyere), 0, self::MAKS_UNDERSOKT) as $u) {
            try {
                if ($this->kanOppdateresFra($p, $u, $fra)) {
                    $kan[] = $u;
                }
            } catch (BivrostFeil) {
                // En eldre versjon med en ødelagt eller manglende versjon.json skal ikke stoppe letingen etter et stopp.
            }
        }

        return array_reverse($kan);
    }

    /** Om versjonen $u kan legges inn over den installerte versjonen $fra av samme pakke (Pakke::kanOppdateresFra()). */
    private function kanOppdateresFra(Pakke $p, Utgivelse $u, string $fra): bool
    {
        return Pakke::kanOppdateresFra($fra, $this->info($p, $u)['oppdateres_fra'] ?? null);
    }

    /**
     * Om noen av versjonene planen alt har lastet versjon.json for, ikke kan nås fra den installerte versjonen. Laster
     * ikke ned noe.
     *
     * @param list<Utgivelse> $provd
     */
    private function harUtilgjengelig(Pakke $p, array $provd): bool
    {
        foreach ($provd as $u) {
            if ($u->info !== null && !Pakke::kanOppdateresFra($this->installertVersjon($p), $u->info['oppdateres_fra'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Om hver versjon i kombinasjonen kan legges inn over den installerte versjonen av sin pakke.
     *
     * @param list<?Utgivelse> $komb Én per pakke, i samme rekkefølge som $this->pakker (null = bli på installert).
     */
    private function kanNas(array $komb): bool
    {
        foreach ($this->pakker as $i => $p) {
            $u = $komb[$i] ?? null;
            if ($u !== null && !$this->kanOppdateresFra($p, $u, $this->installertVersjon($p))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, list<Utgivelse>> $valg */
    private function harNyere(array $valg): bool
    {
        foreach ($valg as $l) {
            if ($l !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alle kombinasjonene, de nyeste først (den første pakken er den viktigste).
     *
     * @param list<list<?Utgivelse>> $valg
     * @return \Generator<int, list<?Utgivelse>>
     */
    private function kombinasjoner(array $valg): \Generator
    {
        if ($valg === []) {
            yield [];

            return;
        }
        $forste = array_shift($valg);
        foreach ($forste as $u) {
            foreach ($this->kombinasjoner($valg) as $resten) {
                yield array_merge([$u], $resten);
            }
        }
    }

    /**
     * @param array<string, string> $mal
     * @param array<string, array<string, mixed>> $info
     */
    private function passer(array $mal, array $info): bool
    {
        foreach ($info as $i) {
            $krever = is_array($i['krever'] ?? null) ? $i['krever'] : [];
            foreach ($krever as $hva => $krav) {
                if (!is_string($krav)) {
                    return false;
                }
                $versjon = $hva === 'php' ? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION : ($mal[$hva] ?? null);
                try {
                    if ($versjon === null || !Semver::satisfies($versjon, $krav)) {
                        return false;
                    }
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function info(Pakke $p, Utgivelse $u): array
    {
        if ($u->info !== null) {
            return $u->info;
        }
        $f = $this->mappe() . '/info-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $this->kilde->lastNed($p, $u, $u->tag . '.versjon.json', $f, self::MAKS_LITEN);
            $j = Pakkeleser::json($f);
        } finally {
            @unlink($f);
        }
        if (($j['pakke'] ?? null) !== $p->id || ($j['versjon'] ?? null) !== $u->versjon || ($j['tag'] ?? null) !== $u->tag) {
            throw new BivrostFeil('bivrost.feil.oppdatering.versjonsfil');
        }

        return $u->info = $j;
    }

    /**
     * Gjør neste steg. Resultat: ingenting | hentet | filer | database.
     *
     * @return array{resultat: string, korrelasjon: string, detaljer: array<string, mixed>}
     */
    public function kjor(bool $fraApi = false): array
    {
        $las = @fopen($this->mappe() . '/las', 'c');
        if ($las === false) {
            throw new BivrostFeil('bivrost.feil.oppdatering.arbeid');
        }
        if (!flock($las, LOCK_EX | LOCK_NB)) {
            fclose($las);
            throw new HttpFeil(409, 'oppdatering_pagar', 'bivrost.feil.oppdatering.pagar');
        }
        try {
            @set_time_limit(300);
            $this->klarerInstallerte('installert');
            $t = $this->tilstand();
            if ($t === null) {
                if ($this->holdtIgjen() && $fraApi) {
                    throw new HttpFeil(409, 'oppdatering_holdt_igjen', 'bivrost.feil.oppdatering.holdt_igjen');
                }
                try {
                    $plan = $this->plan();
                } catch (BivrostFeil $e) {
                    // Kategorien sikkerhet slettes etter oppbevaringstiden, så et maskin-API som spør hver time mens
                    // tokenet mangler, ikke fyller revisjonsloggen.
                    $this->loggFeil(null, 'plan', $e, null, [], 'sikkerhet');
                    throw self::somHttpFeil($e);
                }
                if ($plan === []) {
                    foreach ($this->pakker as $p) {
                        if (($this->migrator)($p)->status()['ventende'] !== []) {
                            return ['resultat' => 'database', 'korrelasjon' => $this->logg->korrelasjon(), 'detaljer' => $this->databasesteg()];
                        }
                    }

                    return ['resultat' => 'ingenting', 'korrelasjon' => $this->logg->korrelasjon(), 'detaljer' => []];
                }
                $t = [
                    'steg' => 'hent',
                    'korrelasjon' => $this->logg->korrelasjon(),
                    'startet' => $this->naa(DATE_ATOM),
                    'plan' => array_map(static fn (array $x): array => array_diff_key($x, ['utgivelse' => true]), $plan),
                    'hentet' => [],
                ];
                $this->slettMappe($this->mappe() . '/klar');
                $this->lagreTilstand($t);
            }
            $korr = is_string($t['korrelasjon'] ?? null) ? $t['korrelasjon'] : $this->logg->korrelasjon();
            $this->logg->fortsettKorrelasjon($korr);

            return match ($t['steg'] ?? null) {
                'hent' => ['resultat' => 'hentet', 'korrelasjon' => $korr, 'detaljer' => $this->hentNeste($t)],
                'filer' => ['resultat' => 'filer', 'korrelasjon' => $korr, 'detaljer' => $this->filsteg($t)],
                'database' => ['resultat' => 'database', 'korrelasjon' => $korr, 'detaljer' => $this->databasesteg($t)],
                default => throw new BivrostFeil('bivrost.feil.oppdatering.tilstand'),
            };
        } finally {
            flock($las, LOCK_UN);
            fclose($las);
        }
    }

    private function pakke(string $id): Pakke
    {
        foreach ($this->pakker as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }
        throw new BivrostFeil('bivrost.feil.oppdatering.tilstand');
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function hentNeste(array $t): array
    {
        $plan = is_array($t['plan'] ?? null) ? $t['plan'] : [];
        $hentet = is_array($t['hentet'] ?? null) ? $t['hentet'] : [];
        foreach ($plan as $x) {
            if (!is_array($x) || !is_string($x['pakke'] ?? null) || !is_string($x['tag'] ?? null) || !is_string($x['til'] ?? null) || isset($hentet[$x['pakke']])) {
                continue;
            }
            $p = $this->pakke($x['pakke']);
            try {
                $resultat = $this->hent($p, $x['tag'], $x['til'], $this->malFraPlan($plan));
            } catch (\Throwable $e) {
                $this->lagreTilstand(null);
                $this->slettMappe($this->mappe() . '/klar');
                $this->loggFeil($p, 'hent', $e, $x['til']);
                throw self::somHttpFeil($e);
            }
            $hentet[$p->id] = $resultat;
            $t['hentet'] = $hentet;
            if (count($hentet) === count($plan)) {
                $t['steg'] = 'filer';
            }
            $this->lagreTilstand($t);
            $this->logg->skriv('oppdatering:hentet', $this->objekt($p), [], ['versjon' => Verdi::tekst($x['til'])], ['tag' => $x['tag'], 'signert' => $resultat['signert']], 'revisjon', null);

            return ['pakke' => $p->id, 'versjon' => $x['til'], 'signert' => $resultat['signert']];
        }
        $t['steg'] = 'filer';
        $this->lagreTilstand($t);

        return [];
    }

    /**
     * Versjonene hver pakke får når planen er lagt inn: målversjonen i planen, ellers den installerte.
     *
     * @param array<mixed> $plan Planen fra tilstand.json.
     * @return array<string, string>
     */
    private function malFraPlan(array $plan): array
    {
        $mal = [];
        foreach ($this->pakker as $p) {
            $mal[$p->id] = $this->installertVersjon($p);
        }
        foreach ($plan as $x) {
            if (is_array($x) && is_string($x['pakke'] ?? null) && is_string($x['til'] ?? null) && isset($mal[$x['pakke']])) {
                $mal[$x['pakke']] = $x['til'];
            }
        }

        return $mal;
    }

    /**
     * @param array<string, string> $mal Versjonene i den lagrede planen (malFraPlan()), til kontrollen av «krever».
     * @return array{sha256: string, signert: bool, filer: int}
     */
    private function hent(Pakke $p, string $tag, string $versjon, array $mal): array
    {
        $u = null;
        foreach ($this->kilde->utgivelser($p) as $x) {
            if ($x->tag === $tag) {
                $u = $x;
            }
        }
        if ($u === null || $u->versjon !== $versjon) {
            throw new BivrostFeil('bivrost.feil.oppdatering.tag_ukjent');
        }
        $forventet = $this->forventetNokkel($p);
        $klarert = $this->utgivere->hent($p->id);
        $harSignatur = $u->har($u->zip() . '.sig');
        if ($forventet !== null && !$harSignatur) {
            throw new BivrostFeil('bivrost.feil.oppdatering.usignert');
        }
        $m = $this->mappe();
        $zip = "$m/{$p->id}.zip";
        $smaa = "$m/{$p->id}.liten";
        try {
            $this->kilde->lastNed($p, $u, $u->zip(), $zip, self::MAKS_ZIP);
            $faktisk = (string) hash_file('sha256', $zip);
            $this->kilde->lastNed($p, $u, $u->zip() . '.sha256', $smaa, self::MAKS_LITEN);
            $oppgitt = strtolower((string) strtok(trim((string) file_get_contents($smaa)), " \t\n"));
            if (preg_match('/^[0-9a-f]{64}$/D', $oppgitt) !== 1 || !hash_equals($oppgitt, $faktisk)) {
                throw new BivrostFeil('bivrost.feil.oppdatering.sjekksum');
            }
            if ($u->digest !== null && str_starts_with($u->digest, 'sha256:') && !hash_equals(substr($u->digest, 7), $faktisk)) {
                throw new BivrostFeil('bivrost.feil.oppdatering.sjekksum');
            }
            $signatur = null;
            if ($harSignatur) {
                $this->kilde->lastNed($p, $u, $u->zip() . '.sig', $smaa, self::MAKS_LITEN);
                $signatur = (string) file_get_contents($smaa);
            }
            $stemmer = static fn (string $n): bool => $signatur !== null && Signatur::stemmer($n, $p->id, $tag, $faktisk, $signatur);
            if ($forventet !== null && !$stemmer($forventet['nokkel'])) {
                // Har pakken en ny nøkkel og er signert med den, er det et bytte uten den klarerte nøkkelen.
                $ny = Utgivernokler::iZip($zip, $p);
                $bytte = $ny !== null && $ny !== $forventet['nokkel'] && $ny !== ($klarert['nokkel'] ?? null) && $stemmer($ny);
                throw new BivrostFeil($bytte ? 'bivrost.feil.oppdatering.nokkel_byttet' : 'bivrost.feil.oppdatering.signatur');
            }
            $signert = $forventet !== null;
            $arbeid = "$m/klar/{$p->id}";
            $this->slettMappe($arbeid);
            if (!@mkdir($arbeid, 0750, true) && !is_dir($arbeid)) {
                throw new BivrostFeil('bivrost.feil.oppdatering.arbeid');
            }
            $r = Pakkeleser::pakkUt($p, $zip, $tag, $versjon, $arbeid);
            // versjon.json i pakken (dekket av sjekksummen og signaturen) må svare til den som ble brukt i planen.
            $info = $this->info($p, $u);
            // <tag>.versjon.json er ikke signert; planen bygger på den. Kravene og mellomstoppet må derfor være de samme
            // som i den signerte pakken. Mellomstoppet og «krever» kontrolleres i tillegg mot den signerte versjon.json
            // direkte, med versjonene i den lagrede planen: planen kan være laget i et tidligere kall med en fil som
            // siden er byttet tilbake (TOCTOU).
            if (
                json_encode($r['versjon']['krever'] ?? []) !== json_encode($info['krever'] ?? [])
                || json_encode($r['versjon']['oppdateres_fra'] ?? null) !== json_encode($info['oppdateres_fra'] ?? null)
                || !Pakke::kanOppdateresFra($this->installertVersjon($p), $r['versjon']['oppdateres_fra'] ?? null)
                || !$this->passer($mal, [$p->id => $r['versjon']])
            ) {
                throw new BivrostFeil('bivrost.feil.oppdatering.versjonsfil');
            }
            $iPakken = Utgivernokler::lesFil("$arbeid/" . $p->nokkelfil(), $p->id, $u->zip() . ':' . $p->nokkelfil());
            $kilde = $this->nyTillit($iPakken, $klarert, $forventet, $stemmer);
            $klarer = $iPakken !== null && $kilde !== null ? ['nokkel' => $iPakken, 'kilde' => $kilde] : null;
            // Den første nøkkelen klareres bare når pakken er signert med den (kontrollert i nyTillit).
            $signert = $signert || $klarer !== null;
            $klar = ['tag' => $tag, 'versjon' => $versjon, 'sha256' => $faktisk, 'filer' => $r['filer'], 'klarer' => $klarer];
            file_put_contents("$m/klar/{$p->id}.json", json_encode($klar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return ['sha256' => $faktisk, 'signert' => $signert, 'filer' => count($r['filer'])];
        } finally {
            @unlink($zip);
            @unlink($smaa);
        }
    }

    /**
     * Nøkkelen pakken skal kontrolleres mot: den i .env (går foran), ellers den klarerte, ellers ingen.
     *
     * @return array{nokkel: string, kilde: string}|null
     */
    private function forventetNokkel(Pakke $p): ?array
    {
        $env = trim($this->nokler[$p->id] ?? '');
        if ($env !== '') {
            $n = Utgivernokler::normaliser($env);
            if ($n === null) {
                throw new BivrostFeil('bivrost.feil.oppdatering.signaturnokkel');
            }

            return ['nokkel' => $n, 'kilde' => 'env'];
        }
        $k = $this->utgivere->hent($p->id);

        return $k === null ? null : ['nokkel' => $k['nokkel'], 'kilde' => 'klarert'];
    }

    /**
     * Om utgivernøkkelen i pakken skal klareres (gir kilden), eller null når den klarerte blir stående. Signaturen
     * mot den forventede nøkkelen er alt kontrollert. Kaster når pakken bytter nøkkelen uten å være signert med den
     * klarerte (eller med den nye nøkkelen satt i .env av driften), og når en første nøkkel ikke har signert pakken.
     *
     * @param array{nokkel: string, kilde: string, versjon: ?string, klarert: string, fingeravtrykk: string}|null $klarert
     * @param array{nokkel: string, kilde: string}|null $forventet
     * @param callable(string): bool $stemmer
     */
    private function nyTillit(?string $iPakken, ?array $klarert, ?array $forventet, callable $stemmer): ?string
    {
        if ($iPakken === null || ($klarert !== null && hash_equals($klarert['nokkel'], $iPakken))) {
            return null;
        }
        $fraEnv = $forventet !== null && $forventet['kilde'] === 'env' && hash_equals($forventet['nokkel'], $iPakken);
        if ($klarert === null) {
            if ($forventet === null) {
                if (!$stemmer($iPakken)) {
                    throw new BivrostFeil('bivrost.feil.oppdatering.nokkel_usignert');
                }

                return 'oppdatering';
            }

            // Kontrollert med nøkkelen i .env: driften går god for pakken, også for nøkkelen den har med seg.
            return $fraEnv ? 'env' : 'oppdatering';
        }
        if ($stemmer($klarert['nokkel'])) {
            return 'bytte';
        }
        if ($fraEnv) {
            return 'env';
        }
        throw new BivrostFeil('bivrost.feil.oppdatering.nokkel_byttet');
    }

    /**
     * Klarerer utgivernøkkelen i de installerte filene for pakkene som ikke har en klarert nøkkel ennå: ved
     * installasjonen, og i installasjoner fra før BIV-DRF-14 første gang den nye koden oppdaterer. Gir antallet.
     */
    public function klarerInstallerte(string $kilde): int
    {
        $n = 0;
        foreach ($this->pakker as $p) {
            if ($this->utgivere->hent($p->id) !== null) {
                continue;
            }
            $fil = Utgivernokler::lesFil($this->rot . '/' . $p->nokkelfil(), $p->id, $p->nokkelfil());
            if ($fil === null) {
                continue;
            }
            $v = $this->installert($p)['versjon'] ?? null;
            $this->utgivere->lagre($p->id, $fil, $kilde, is_string($v) ? $v : null);
            $this->loggNokkel($p, null, $fil, $kilde, is_string($v) ? $v : null);
            $n++;
        }

        return $n;
    }

    /**
     * Utgivernøkkelen for statusen (uten å endre noe): kilde env | klarert | installert (klareres ved neste
     * oppdatering) | null (signatur kreves ikke).
     *
     * @return array{kilde: ?string, fingeravtrykk: ?string, klarert: array{kilde: string, versjon: ?string, klarert: string, fingeravtrykk: string}|null, feil: ?string}
     */
    private function nokkelstatus(Pakke $p): array
    {
        try {
            $f = $this->forventetNokkel($p);
            $k = $this->utgivere->hent($p->id);
            if ($f === null) {
                $fil = Utgivernokler::lesFil($this->rot . '/' . $p->nokkelfil(), $p->id, $p->nokkelfil());
                $f = $fil === null ? null : ['nokkel' => $fil, 'kilde' => 'installert'];
            }
        } catch (BivrostFeil $e) {
            return ['kilde' => null, 'fingeravtrykk' => null, 'klarert' => null, 'feil' => $e->tekstnokkel];
        }

        return [
            'kilde' => $f['kilde'] ?? null,
            'fingeravtrykk' => $f === null ? null : Utgivernokler::fingeravtrykk($f['nokkel']),
            'klarert' => $k === null ? null : ['kilde' => $k['kilde'], 'versjon' => $k['versjon'], 'klarert' => $k['klarert'], 'fingeravtrykk' => $k['fingeravtrykk']],
            'feil' => null,
        ];
    }

    private function loggNokkel(Pakke $p, ?string $fra, string $til, string $kilde, ?string $versjon): void
    {
        $this->logg->skriv(
            'oppdatering:nokkel',
            $this->objekt($p),
            $fra === null ? [] : ['fingeravtrykk' => Verdi::tekst(Utgivernokler::fingeravtrykk($fra))],
            ['fingeravtrykk' => Verdi::tekst(Utgivernokler::fingeravtrykk($til))],
            ['kilde' => $kilde, 'versjon' => $versjon],
        );
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private function filsteg(array $t): array
    {
        $m = $this->mappe();
        $pakker = [];
        foreach (is_array($t['plan'] ?? null) ? $t['plan'] : [] as $x) {
            if (is_array($x) && is_string($x['pakke'] ?? null)) {
                $p = $this->pakke($x['pakke']);
                $klar = Pakkeleser::json("$m/klar/{$p->id}.json");
                $filer = [];
                foreach (is_array($klar['filer'] ?? null) ? $klar['filer'] : [] as $rel => $sha) {
                    if (is_string($rel) && is_string($sha)) {
                        Pakkeleser::sjekkNavn($p, $rel);
                        if (!hash_equals($sha, (string) @hash_file('sha256', "$m/klar/{$p->id}/$rel"))) {
                            throw new BivrostFeil('bivrost.feil.oppdatering.arbeid_endret');
                        }
                        $filer[$rel] = $sha;
                    }
                }
                $kl = $klar['klarer'] ?? null;
                $klarer = null;
                if (is_array($kl) && is_string($kl['nokkel'] ?? null) && is_string($kl['kilde'] ?? null)) {
                    // Nøkkelen som klareres, må være den i arbeidsmappen (kontrollert mot sjekksummen over).
                    if (Utgivernokler::lesFil("$m/klar/{$p->id}/" . $p->nokkelfil(), $p->id, "klar/{$p->id}/" . $p->nokkelfil()) !== $kl['nokkel']) {
                        throw new BivrostFeil('bivrost.feil.oppdatering.arbeid_endret');
                    }
                    $klarer = ['nokkel' => $kl['nokkel'], 'kilde' => $kl['kilde']];
                }
                $pakker[] = ['pakke' => $p, 'filer' => $filer, 'fra' => is_string($x['fra'] ?? null) ? $x['fra'] : '', 'til' => is_string($x['til'] ?? null) ? $x['til'] : '', 'klarer' => $klarer];
            }
        }
        // Hva endres: filer med annet innhold, og filer i pakkens mappe som ikke er med i den nye versjonen.
        $endringer = [];
        foreach ($pakker as $i => $x) {
            $p = $x['pakke'];
            $endret = [];
            foreach ($x['filer'] as $rel => $sha) {
                $mal = $this->rot . '/' . $rel;
                if (!is_file($mal) || !hash_equals($sha, (string) @hash_file('sha256', $mal))) {
                    $endret[] = $rel;
                }
            }
            $sist = [$p->manifest(), $p->versjonsfil()];
            usort($endret, static function (string $a, string $b) use ($sist): int {
                $s = (int) in_array($a, $sist, true) <=> (int) in_array($b, $sist, true);

                return $s !== 0 ? $s : strcmp($a, $b);
            });
            $fjern = array_values(array_filter($this->filerUnder($p->mappe), static fn (string $r): bool => !isset($x['filer'][$r])));
            $endringer[$i] = ['endret' => $endret, 'fjern' => $fjern];
            $this->sjekkSkriving($endret, $fjern);
        }
        $mapper = array_map(static fn (array $x): string => $x['pakke']->mappe, $pakker);
        try {
            $db = $this->dbKopi->ta();
            $filer = $this->filKopi->ta($mapper);
        } catch (\Throwable $e) {
            $this->lagreTilstand(null);
            $this->loggFeil(null, 'kopi', $e, null);
            throw new BivrostFeil('bivrost.feil.oppdatering.kopi', [], $e);
        }
        $punkt = $this->lagPunkt($db, $filer, $pakker, 'oppdatering');
        $antall = 0;
        try {
            foreach ($pakker as $i => $x) {
                $p = $x['pakke'];
                foreach ($endringer[$i]['endret'] as $rel) {
                    $this->flytt("$m/klar/{$p->id}/$rel", $this->rot . '/' . $rel);
                    $antall++;
                }
                foreach ($endringer[$i]['fjern'] as $rel) {
                    if (!@unlink($this->rot . '/' . $rel)) {
                        throw new BivrostFeil('bivrost.feil.oppdatering.fjern', ['fil' => $rel]);
                    }
                }
                $this->ryddTomme($this->rot . '/' . rtrim($p->mappe, '/'));
            }
            // Ny eller byttet utgivernøkkel (BIV-DRF-14), først når filene er på plass. Et bytte går bare fremover: en
            // tilbakerulling til punktet over beholder den nye nøkkelen (Tilbakerulling, BIV-B-034).
            foreach ($pakker as $x) {
                if ($x['klarer'] !== null) {
                    $forrige = $this->utgivere->hent($x['pakke']->id);
                    $this->utgivere->lagre($x['pakke']->id, $x['klarer']['nokkel'], $x['klarer']['kilde'], $x['til']);
                    $this->loggNokkel($x['pakke'], $forrige['nokkel'] ?? null, $x['klarer']['nokkel'], $x['klarer']['kilde'], $x['til']);
                }
            }
        } catch (\Throwable $e) {
            $avvik = $this->filKopi->leggTilbake($filer, $mapper);
            $this->lagreTilstand(null);
            $this->slettMappe("$m/klar");
            $this->loggFeil(null, 'filer', $e, null, ['tilbakerullet' => $avvik === [], 'avvik' => array_slice($avvik, 0, 50), 'punkt' => $punkt]);
            throw new BivrostFeil($avvik === [] ? 'bivrost.feil.oppdatering.filer_tilbake' : 'bivrost.feil.oppdatering.filer_avvik', ['punkt' => $punkt], $e);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->slettMappe("$m/klar");
        $t['steg'] = 'database';
        $t['punkt'] = $punkt;
        $this->lagreTilstand($t);
        foreach ($pakker as $x) {
            $this->logg->skriv(
                'oppdatering:filer',
                $this->objekt($x['pakke']),
                ['versjon' => Verdi::tekst($x['fra'])],
                ['versjon' => Verdi::tekst($x['til'])],
                ['punkt' => $punkt, 'db_kopi' => $db, 'fil_kopi' => $filer]
            );
        }
        $this->lager->rydd([$db, $filer, $punkt]);

        return ['filer' => $antall, 'punkt' => $punkt];
    }

    /**
     * @param array<string, mixed>|null $t
     * @return array<string, mixed>
     */
    private function databasesteg(?array $t = null): array
    {
        $kopi = null;
        $kjort = [];
        foreach ($this->pakker as $p) {
            $mig = ($this->migrator)($p);
            try {
                $k = $mig->kjor(function () use (&$kopi): void {
                    $kopi ??= $this->dbKopi->ta();
                });
            } catch (\Throwable $e) {
                $this->loggFeil($p, 'database', $e, null, ['db_kopi' => $kopi]);
                throw $e;
            }
            if ($k !== []) {
                $kjort[$p->id] = $k;
                $s = $mig->status();
                $this->logg->skriv(
                    'oppdatering:database',
                    $this->objekt($p),
                    [],
                    ['skjemaversjon' => Verdi::kode($s['versjon'], Felt::tekst($s['versjon']))],
                    ['migreringer' => array_map(static fn (array $x): string => $x['nr'] . ' ' . $x['navn'], $k), 'db_kopi' => $kopi]
                );
            }
        }
        if ($t !== null) {
            $this->lagreTilstand(null);
        }

        return ['kjort' => $kjort, 'db_kopi' => $kopi];
    }

    /**
     * @param list<array{pakke: Pakke, filer: array<string, string>, fra: string, til: string, klarer: array{nokkel: string, kilde: string}|null}> $pakker
     */
    private function lagPunkt(string $db, string $filer, array $pakker, string $grunn): string
    {
        $navn = $this->lager->nyttNavn('punkt', '.json');
        $innhold = [
            'format' => 'bivrost-gjenopprettingspunkt',
            'laget' => $this->naa('Y-m-d\TH:i:s\Z'),
            'grunn' => $grunn,
            'db' => $db,
            'filer' => $filer,
            'korrelasjon' => $this->logg->korrelasjon(),
            'pakker' => array_map(static fn (array $x): array => ['id' => $x['pakke']->id, 'mappe' => $x['pakke']->mappe, 'versjon' => $x['fra']], $pakker),
        ];
        file_put_contents($this->lager->mappe() . '/' . $navn, json_encode($innhold, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $navn;
    }

    /** @return list<string> Filene under en mappe (relative til roten), ikke lenker. */
    private function filerUnder(string $mappe): array
    {
        $full = $this->rot . '/' . rtrim($mappe, '/');
        if (!is_dir($full)) {
            return [];
        }
        $ut = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && !$f->isLink()) {
                $ut[] = str_replace('\\', '/', substr($f->getPathname(), strlen($this->rot) + 1));
            }
        }
        sort($ut);

        return $ut;
    }

    /**
     * @param list<string> $endret
     * @param list<string> $fjern
     */
    private function sjekkSkriving(array $endret, array $fjern): void
    {
        foreach (array_merge($endret, $fjern) as $rel) {
            $mal = $this->rot . '/' . $rel;
            if (is_file($mal) ? !is_writable(dirname($mal)) : !$this->kanLage(dirname($mal))) {
                throw new BivrostFeil('bivrost.feil.oppdatering.skriv', ['fil' => $rel]);
            }
            if (is_dir($mal)) {
                throw new BivrostFeil('bivrost.feil.oppdatering.skriv', ['fil' => $rel]);
            }
        }
    }

    private function kanLage(string $mappe): bool
    {
        while (!is_dir($mappe)) {
            $mappe = dirname($mappe);
        }

        return is_writable($mappe);
    }

    private function flytt(string $fra, string $til): void
    {
        if (!is_dir(dirname($til)) && !@mkdir(dirname($til), 0755, true) && !is_dir(dirname($til))) {
            throw new BivrostFeil('bivrost.feil.oppdatering.skriv', ['fil' => $til]);
        }
        $tmp = $til . '.ny-' . bin2hex(random_bytes(4));
        if (!@copy($fra, $tmp) || !@chmod($tmp, 0644) || !@rename($tmp, $til)) {
            @unlink($tmp);
            throw new BivrostFeil('bivrost.feil.oppdatering.skriv', ['fil' => basename($til)]);
        }
    }

    private function ryddTomme(string $mappe): void
    {
        if (!is_dir($mappe)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($mappe, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            }
        }
    }

    /** Sletter en mappe Bivrost selv har laget under driftsmappen (aldri noe annet). */
    private function slettMappe(string $mappe): void
    {
        if (!is_dir($mappe) || !str_starts_with($mappe, $this->drift . '/oppdatering/')) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($mappe, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo) {
                $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        }
        @rmdir($mappe);
    }

    private function objekt(Pakke $p): LoggObjekt
    {
        return new LoggObjekt(null, 'pakke', $this->logg->tekst('bivrost.objekttype.pakke'), $p->id, $p->navn);
    }

    /**
     * Et svar fra GitHub som ikke er 200, blir 502 (feil hos tjenesten bak) med statuskoden i teksten, ikke 500
     * intern_feil med bare tekstnøkkelen i PHP-feilloggen. Driftsbrukeren må kunne skille 401, 403 og 404 for å vite
     * om tokenet er ugyldig, mangler tilgang eller mangler (kjerne/DRIFT.md avsnitt 4).
     */
    private static function somHttpFeil(\Throwable $e): \Throwable
    {
        if ($e instanceof BivrostFeil && !$e instanceof HttpFeil && str_starts_with($e->tekstnokkel, 'bivrost.feil.oppdatering.github')) {
            return new HttpFeil(502, 'github_feil', $e->tekstnokkel, $e->verdier, [], $e);
        }

        return $e;
    }

    /** @param array<string, mixed> $ekstra */
    private function loggFeil(?Pakke $p, string $steg, \Throwable $e, ?string $til, array $ekstra = [], string $kategori = 'revisjon'): void
    {
        $this->logg->skrivTrygt('oppdatering:feil', $p !== null ? $this->objekt($p) : null, [
            'steg' => $steg,
            'feil' => $e instanceof BivrostFeil ? $e->tekstnokkel : 'bivrost.feil.ukjent',
            'feil_tekst' => $e instanceof BivrostFeil ? $this->logg->tekst($e->tekstnokkel, $e->verdier) : $this->logg->tekst('bivrost.feil.ukjent'),
            'til' => $til,
        ] + $ekstra, $kategori);
    }
}
