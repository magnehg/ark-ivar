<?php

declare(strict_types=1);

namespace Bivrost\Kjerne;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Eksport\Eksport;
use Bivrost\Kjerne\Eksport\EksportBidrag;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Csrf;
use Bivrost\Kjerne\Http\Feilside;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Ruter;
use Bivrost\Kjerne\Http\Sikkerhetshoder;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Http\UtgaendeHttp;
use Bivrost\Kjerne\Innlogging\Forsok;
use Bivrost\Kjerne\Innlogging\LokalBrukere;
use Bivrost\Kjerne\Innlogging\LokalInnlogging;
use Bivrost\Kjerne\Innlogging\Totp;
use Bivrost\Kjerne\Installasjon\Installasjon;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Konfig\Env;
use Bivrost\Kjerne\Krypto\Hemmelighetsboks;
use Bivrost\Kjerne\Logg\Aktor;
use Bivrost\Kjerne\Logg\DbLoggLager;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Logg\Syslog;
use Bivrost\Kjerne\Logg\VideresendendeLoggLager;
use Bivrost\Kjerne\Migrering\Migrator;
use Bivrost\Kjerne\Oidc\OidcFeil;
use Bivrost\Kjerne\Oidc\OidcKlient;
use Bivrost\Kjerne\Oidc\OidcOppsett;
use Bivrost\Kjerne\Oidc\StandardOidcKlient;
use Bivrost\Kjerne\Okt\Appbrukere;
use Bivrost\Kjerne\Okt\Innlogget;
use Bivrost\Kjerne\Okt\Okt;
use Bivrost\Kjerne\Oppdatering\GithubUtgivelser;
use Bivrost\Kjerne\Oppdatering\OppdateringsApi;
use Bivrost\Kjerne\Oppdatering\Oppdaterer;
use Bivrost\Kjerne\Oppdatering\Pakke;
use Bivrost\Kjerne\Oppdatering\Tilbakerulling;
use Bivrost\Kjerne\Oppdatering\Utgivelseskilde;
use Bivrost\Kjerne\Oppdatering\Utgivernokler;
use Bivrost\Kjerne\Preferanser\HttpSentralPreferanser;
use Bivrost\Kjerne\Preferanser\Preferanser;
use Bivrost\Kjerne\Preferanser\Preferansetjeneste;
use Bivrost\Kjerne\Preferanser\SentralPreferanser;
use Bivrost\Kjerne\Ressurser\Ressurser;
use Bivrost\Kjerne\Sikkerhetskopi\Databasekopi;
use Bivrost\Kjerne\Sikkerhetskopi\Filkopi;
use Bivrost\Kjerne\Sikkerhetskopi\Kopilager;
use Bivrost\Kjerne\Sprak\Klartekst;
use Bivrost\Kjerne\Sprak\Tekster;
use Bivrost\Kjerne\Tilgang\Rbac;
use Bivrost\Kjerne\Tilgang\Rolleregister;
use Bivrost\Kjerne\Tilgang\Urn;
use Psr\Clock\ClockInterface;

/**
 * Bivrost Kjerne i én app (BIV-APP-01–04): setter sammen tjenestene for én forespørsel og har Kjernens egne ruter
 * under /bivrost/. Appen lager én Kjerne per forespørsel og gir den sin konfigurasjon:
 *
 *     $kjerne = Kjerne::fraKonfig(require __DIR__ . '/../app/bivrost.php');
 *     $kjerne->handter(Foresporsel::fraGlobale(), fn (Foresporsel $f, Kjerne $k): ?Svar => app_ruter($f, $k))->send();
 *
 * Ingen tilstand deles mellom forespørsler. Tjenestene lages først når de trengs. Appen bruker innlogget(),
 * krev(urn), logg(), db() og kontekst() i sin egen kode.
 */
final class Kjerne
{
    public const PAKKE = 'bivrost-kjerne';
    public const MAPPE = 'bivrost/kjerne/';

    private Kontekst $kontekst;
    private ?Db $db = null;
    private ?Env $env = null;
    private ?Tekster $tekster = null;
    private ?Rolleregister $roller = null;
    private ?Logg $logg = null;
    private ?Okt $okt = null;
    private ?Forsok $forsok = null;
    private ?Oppdaterer $oppdaterer = null;
    private ?Kopilager $kopilager = null;
    private ?OidcKlient $oidc = null;
    private bool $oidcSatt = false;
    private ?Appbrukere $appbrukere = null;
    private ?SentralPreferanser $sentralPref = null;
    private bool $sentralPrefSatt = false;
    private ?Utgivelseskilde $kilde = null;
    private string $sprak = 'nb';
    private bool $https = false;

    /** @var (callable(): void)|null */
    private $vent = null;

    public function __construct(
        public readonly AppKonfig $app,
        private readonly ClockInterface $klokke = new Klokke(),
        private readonly ?EksportBidrag $eksport = null,
    ) {
        $this->kontekst = new Kontekst(Aktor::system(), PHP_SAPI === 'cli' ? 'kommandolinje' : 'nett');
    }

    /**
     * Lager Kjerne fra appens konfigurasjon og slår av visning av feil (feil skal aldri vises til brukeren).
     *
     * @param array<string, mixed> $konfig
     */
    public static function fraKonfig(array $konfig, ?EksportBidrag $eksport = null): self
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('session.use_strict_mode', '1');

        return new self(AppKonfig::fraListe($konfig), new Klokke(), $eksport);
    }

    // ---------------------------------------------------------------- tjenester

    public function kontekst(): Kontekst
    {
        return $this->kontekst;
    }

    public function env(): Env
    {
        return $this->env ??= Env::fraFil($this->app->envFil);
    }

    public function tekster(): Tekster
    {
        return $this->tekster ??= new Tekster(
            array_values(array_filter([dirname(__DIR__) . '/i18n', $this->app->i18n, $this->app->sprakpakker], static fn (?string $m): bool => $m !== null && is_dir($m))),
            $this->app->hovedsprak,
        );
    }

    public function db(): Db
    {
        return $this->db ??= Db::fraEnv($this->env());
    }

    private function boks(): Hemmelighetsboks
    {
        return Hemmelighetsboks::fraBase64($this->env()->krev('BIVROST_KRYPTERINGSNOKKEL'));
    }

    public function roller(): Rolleregister
    {
        return $this->roller ??= new Rolleregister($this->db(), $this->app, $this->tekster());
    }

    public function rbac(): Rbac
    {
        return new Rbac($this->app, $this->roller());
    }

    public function logg(): Logg
    {
        if ($this->logg !== null) {
            return $this->logg;
        }
        $lager = new DbLoggLager($this->db());
        $syslog = $this->env()->hent('BIVROST_SYSLOG');

        return $this->logg = new Logg(
            $syslog !== null && $syslog !== '' ? new VideresendendeLoggLager($lager, $syslog, $this->app->app, pen: Syslog::pen($this->env()->hent('BIVROST_SYSLOG_PEN'))) : $lager,
            new Klartekst($this->tekster(), $this->app->hovedsprak, $this->roller()),
            $this->kontekst,
            $this->app,
            $this->klokke,
        );
    }

    public function okt(): Okt
    {
        return $this->okt ??= new Okt($this->db(), $this->klokke, $this->app->oktInaktivMinutter * 60, $this->app->oktMaksTimer * 3600, $this->https);
    }

    /**
     * Begrensningen av forsøk (BIV-AUT-06). Offentlig i Ark-Ivars kopi, så appens egen innlogging og registrering
     * bruker den samme tabellen og de samme nøklene som nødinnloggingen.
     */
    public function forsok(): Forsok
    {
        return $this->forsok ??= new Forsok($this->db(), $this->klokke, $this->env()->krev('BIVROST_HMAC_NOKKEL'));
    }

    public function migrator(Pakke $p): Migrator
    {
        return new Migrator($this->db(), $p->id, $p->migreringer ?? $this->app->rot . '/' . $p->mappe . 'migrasjoner');
    }

    /** @return list<Pakke> Kjerne først, så appen. */
    public function pakker(): array
    {
        $env = $this->env();
        $ut = [new Pakke(self::PAKKE, 'Bivrost Kjerne', $env->hent('BIVROST_REPO', 'magnehg/ark-ivar') ?? 'magnehg/ark-ivar', 'bivrost-kjerne-v', self::MAPPE, dirname(__DIR__) . '/migrasjoner')];
        if ($this->app->pakke !== null) {
            $ut[] = new Pakke($this->app->app, $this->app->appnavn, $this->app->pakke['repo'], $this->app->pakke['tagprefiks'], $this->app->pakke['mappe'], $this->app->migreringer);
        } elseif ($this->app->migreringer !== null) {
            $ut[] = new Pakke($this->app->app, $this->app->appnavn, 'lokal/lokal', $this->app->app . '-v', 'app-lokal/', $this->app->migreringer);
        }

        return $ut;
    }

    /** Kjører alle ventende migreringer (Kjerne først). */
    public function migrerAlle(): void
    {
        foreach ($this->pakker() as $p) {
            $this->migrator($p)->kjor();
        }
    }

    public function kopilager(): Kopilager
    {
        return $this->kopilager ??= new Kopilager($this->app->drift . '/sikkerhetskopier');
    }

    private function dbKopi(): Databasekopi
    {
        return new Databasekopi($this->db(), array_merge(['bivrost_'], $this->app->tabellprefikser), $this->kopilager());
    }

    private function filKopi(): Filkopi
    {
        return new Filkopi($this->app->rot, $this->kopilager());
    }

    public function settUtgivelseskilde(Utgivelseskilde $k): void
    {
        $this->kilde = $k;
        $this->oppdaterer = null;
    }

    public function oppdaterer(): Oppdaterer
    {
        if ($this->oppdaterer !== null) {
            return $this->oppdaterer;
        }
        $env = $this->env();
        $api = $env->hent('GITHUB_API_URL', 'https://api.github.com') ?? 'https://api.github.com';
        $kilde = $this->kilde ?? new GithubUtgivelser(new UtgaendeHttp(), $api, $env->hent('BIVROST_GITHUB_TOKEN', '') ?? '');
        $nokler = [self::PAKKE => $env->hent('BIVROST_OFFENTLIG_NOKKEL', '') ?? ''];
        if ($this->app->pakke !== null) {
            $nokler[$this->app->app] = $env->hent(strtoupper(str_replace('-', '_', $this->app->app)) . '_OFFENTLIG_NOKKEL', '') ?? '';
        }

        return $this->oppdaterer = new Oppdaterer(
            $this->app->rot,
            $this->app->drift,
            $this->pakker(),
            $kilde,
            $nokler,
            new Utgivernokler($this->db(), $this->klokke),
            $env->sann('BIVROST_FORHANDSVERSJONER'),
            $this->logg(),
            $this->dbKopi(),
            $this->filKopi(),
            $this->kopilager(),
            fn (Pakke $p): Migrator => $this->migrator($p),
            $this->klokke,
        );
    }

    public function oppdateringsApi(): OppdateringsApi
    {
        return new OppdateringsApi($this->db(), $this->oppdaterer(), $this->forsok(), $this->logg(), $this->kontekst);
    }

    /**
     * Appens egne brukere (Ark-Ivars tillegg): uten denne er en økt med metoden «app» aldri gyldig. Settes før
     * handter().
     */
    public function settAppbrukere(?Appbrukere $appbrukere): void
    {
        $this->appbrukere = $appbrukere;
    }

    public function settOidcKlient(?OidcKlient $k): void
    {
        $this->oidc = $k;
        $this->oidcSatt = true;
    }

    public function oidc(): ?OidcKlient
    {
        if (!$this->oidcSatt) {
            $o = OidcOppsett::fraEnv($this->env(), $this->app->app);
            $this->oidc = $o === null ? null : new StandardOidcKlient($o, $this->klokke);
            $this->oidcSatt = true;
        }

        return $this->oidc;
    }

    public function settSentralPreferanser(?SentralPreferanser $s): void
    {
        $this->sentralPref = $s;
        $this->sentralPrefSatt = true;
    }

    public function preferanser(): Preferansetjeneste
    {
        if (!$this->sentralPrefSatt) {
            $url = $this->env()->hent('BIVROST_SENTRAL_URL');
            $this->sentralPref = $url !== null && $url !== '' ? new HttpSentralPreferanser($url) : null;
            $this->sentralPrefSatt = true;
        }

        return new Preferansetjeneste($this->db(), $this->sentralPref);
    }

    /** Byttes ut i tester, så mislykkede forsøk ikke venter. */
    public function settVent(callable $vent): void
    {
        $this->vent = $vent;
    }

    private function lokal(): LokalInnlogging
    {
        return new LokalInnlogging(new LokalBrukere($this->db(), $this->boks()), $this->forsok(), new Totp($this->klokke), $this->logg(), $this->klokke, $this->vent);
    }

    private function installasjon(): Installasjon
    {
        return new Installasjon($this->app, $this->klokke, function (Db $db): void {
            $this->db = $db;
            $this->oppdaterer = null;
            $this->logg = null;
            $this->migrerAlle();
            // Utgivernøkkelen som følger med de installerte pakkene, klareres nå (tillit ved første bruk, BIV-DRF-14).
            $this->oppdaterer()->klarerInstallerte('installasjon');
        });
    }

    // ---------------------------------------------------------------- for appen

    public function innlogget(): ?Innlogget
    {
        return $this->okt?->innlogget();
    }

    /** Kaster 401/403 uten innlogging eller tilgang (sjekket mot rolleregisteret). */
    public function krev(string $urn): Innlogget
    {
        return $this->rbac()->krev($this->innlogget(), $urn);
    }

    public function sprak(): string
    {
        return $this->sprak;
    }

    /**
     * Om installasjonen er fullført (Ark-Ivars tillegg). Appen får sidene sine også før installasjonen, og bruker
     * dette til å vise installasjonssiden uten å røre databasen.
     */
    public function installert(): bool
    {
        try {
            $db = $this->env()->hent('DB_NAVN') !== null ? $this->db() : null;
        } catch (BivrostFeil) {
            $db = null;
        }

        return $this->installasjon()->installert($db);
    }

    // ---------------------------------------------------------------- forespørselen

    /**
     * Håndterer én forespørsel. $app får forespørsler Kjerne ikke har en rute for, og gir et svar eller null (404).
     *
     * @param (callable(Foresporsel, self): ?Svar)|null $app
     */
    public function handter(Foresporsel $f, ?callable $app = null): Svar
    {
        $this->https = $f->https;
        $this->sprak = $this->velgSprak($f);
        $okt = null;
        try {
            $svar = $this->ruter($f, $app, $okt);
            if ($okt instanceof Okt) {
                $okt->lagre($svar);
            }
        } catch (HttpFeil $e) {
            $svar = $this->feilsvar($f, $e->status, $e->kode, $e->tekstnokkel, $e->verdier);
            foreach ($e->hoder as $k => $v) {
                $svar->settHode($k, $v);
            }
            try {
                $okt?->lagre($svar);
            } catch (\Throwable) {
                // Økten skal ikke skjule feilen.
            }
        } catch (\Throwable $e) {
            $forrige = $e->getPrevious();
            error_log(sprintf(
                'Bivrost [%s] %s: %s%s',
                $this->kontekst->korrelasjon(),
                get_class($e),
                $e instanceof BivrostFeil ? $e->tekstnokkel : $e->getMessage(),
                $forrige !== null ? ' (' . get_class($forrige) . ': ' . $forrige->getMessage() . ')' : ''
            ));
            $svar = $this->feilsvar($f, 500, 'intern_feil', 'bivrost.feil.intern');
        }
        $svar->settHode('X-Bivrost-Korrelasjon', $this->kontekst->korrelasjon());

        return Sikkerhetshoder::sett($svar, $f->https);
    }

    /** @param array<string, scalar|null> $v */
    private function feilsvar(Foresporsel $f, int $status, string $kode, string $nokkel, array $v = []): Svar
    {
        $melding = $this->tekster()->tekst($nokkel, $v, $this->sprak);
        if (str_starts_with($f->sti, '/bivrost/api/') || str_contains((string) $f->hode('accept'), 'json')) {
            return Svar::problem($status, $kode, $this->tekster()->tekst('bivrost.feilside.tittel', ['status' => $status], $this->sprak), $melding, $this->kontekst->korrelasjon(), $this->sprak);
        }

        return Feilside::html($this->tekster(), $this->sprak, $status, $melding, $this->kontekst->korrelasjon());
    }

    /**
     * Språket (BIV-SPR-04): brukerens preferanse (fra Sentral eller appen, satt etter innloggingen) → nettleserens
     * Accept-Language → hovedspråket. Bare språk som finnes (innebygde og språkpakker).
     */
    private function velgSprak(Foresporsel $f, ?string $preferanse = null): string
    {
        $finnes = $this->tekster()->sprak();
        $onsker = array_merge($preferanse !== null ? [$preferanse] : [], explode(',', (string) $f->hode('accept-language')));
        foreach ($onsker as $del) {
            $kode = trim(explode(';', $del)[0]);
            if ($kode === '') {
                continue;
            }
            foreach ([$kode, strtolower(explode('-', $kode)[0])] as $k) {
                if (in_array($k, $finnes, true)) {
                    return $k;
                }
            }
            if (in_array(strtolower(substr($kode, 0, 2)), ['no'], true)) {
                return 'nb';
            }
        }

        return $this->app->hovedsprak;
    }


    /** @param (callable(Foresporsel, self): ?Svar)|null $app */
    private function ruter(Foresporsel $f, ?callable $app, ?Okt &$okt): Svar
    {
        // Ingen innlogging over HTTP (BIV-SIK-01): alt nektes over HTTP, også installasjonen. Eneste unntak er
        // BIVROST_TILLAT_HTTP_LOKALT=1 i .env (av som standard), og det virker bare når klient, server og vertsnavn
        // er denne maskinen. Kroppen i en HTTP-forespørsel leses ikke (Foresporsel::fraGlobale).
        if (!$f->https && !($this->env()->sann('BIVROST_TILLAT_HTTP_LOKALT') && $f->loopback())) {
            $adresse = $this->env()->hent('BIVROST_ADRESSE');
            if (in_array($f->metode, ['GET', 'HEAD'], true) && $adresse !== null && str_starts_with($adresse, 'https://')) {
                return Svar::videre($adresse . $f->sti, 308);
            }
            throw new HttpFeil(403, 'krever_https', 'bivrost.feil.http.https');
        }
        if (str_starts_with($f->sti, '/bivrost/ressurser/')) {
            // I versjonspakken ligger designpakken i ressurser/grensesnitt; i en utviklingskopi i repoets grensesnitt/.
            $mappe = is_dir(dirname(__DIR__) . '/ressurser/grensesnitt') ? dirname(__DIR__) . '/ressurser/grensesnitt' : dirname(__DIR__, 2) . '/grensesnitt';
            $r = (new Ressurser($mappe))->svar(substr($f->sti, strlen('/bivrost/ressurser/')));

            return $r ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        }
        if ($f->sti === '/bivrost/helse') {
            return Svar::json(['status' => 'ok']);
        }
        $inst = $this->installasjon();
        $db = null;
        try {
            $db = $this->env()->hent('DB_NAVN') !== null ? $this->db() : null;
        } catch (BivrostFeil) {
            $db = null;
        }
        if (!$inst->installert($db)) {
            return $this->installasjonsruter($f, $inst, $app);
        }
        if (str_starts_with($f->sti, '/bivrost/api/installasjon')) {
            throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        }
        $this->husarbeid();
        $this->roller()->synk($this->logg());
        // Maskinene (Bearer) før økten: de bruker aldri informasjonskapsler.
        $api = $this->oppdateringsApi()->handter($f);
        if ($api !== null) {
            return $api;
        }
        if ($f->sti === '/bivrost/oidc/utlogging' && $f->metode === 'POST') {
            return $this->backchannel($f);
        }
        $okt = $this->okt();
        $okt->start($f);
        $i = $okt->innlogget();
        if ($i !== null && !$this->gyldig($i)) {
            $okt->loggUt();
            $i = null;
        }
        if ($i !== null) {
            $this->kontekst->settAktor($i->aktor);
            $lagret = $this->preferanser()->lagret($this->prefNokkel($i));
            $this->sprak = $this->velgSprak($f, $lagret['app']->over($lagret['felles'])->hent('preferredLanguage'));
        }
        if ($f->endrer() && (str_starts_with($f->sti, '/bivrost/') || $app !== null)) {
            Csrf::krev($f, $okt);
        }
        $svar = $this->kjerneruter($okt)->handter($f);
        if ($svar !== null) {
            return $svar;
        }
        if ($app !== null && !str_starts_with($f->sti, '/bivrost/')) {
            $svar = $app($f, $this);
            if ($svar !== null) {
                return $svar;
            }
        }
        throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
    }

    /**
     * En lokal bruker må fortsatt være aktiv og ha samme passordgenerasjon (passordbytte avslutter eldre økter). Det
     * samme gjelder appens egne brukere, som appen selv kontrollerer (Appbrukere).
     */
    private function gyldig(Innlogget $i): bool
    {
        if ($i->metode === 'app') {
            // Appens egne brukere kontrolleres av appen. Uten tjenesten gjelder ingen slik økt (feil på den trygge siden).
            return $this->appbrukere !== null && $this->appbrukere->gyldig($i);
        }
        if ($i->metode !== 'lokal') {
            return true;
        }
        $b = (new LokalBrukere($this->db(), $this->boks()))->hent((string) $i->aktor->id);

        return $b !== null && Felt::tall($b['aktiv']) === 1 && Felt::tall($b['generasjon']) === $i->generasjon;
    }

    private function prefNokkel(Innlogget $i): string
    {
        return ($i->metode === 'lokal' ? 'lokal:' : '') . (string) $i->aktor->id;
    }

    /** Opprydding uten cron: omtrent hver hundrede forespørsel. */
    private function husarbeid(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        try {
            $this->forsok()->rydd();
            $this->okt()->rydd();
            $this->logg()->lager()->ryddSikkerhetslogg($this->app->loggOppbevaringDager);
            $this->db()->utfor('DELETE FROM bivrost_jti WHERE utloper < ?', [$this->klokke->now()->getTimestamp()]);
        } catch (\Throwable) {
            // Opprydding skal aldri stoppe en forespørsel.
        }
    }

    // ---------------------------------------------------------------- installasjon

    /** @param (callable(Foresporsel, self): ?Svar)|null $app */
    private function installasjonsruter(Foresporsel $f, Installasjon $inst, ?callable $app = null): Svar
    {
        if (!str_starts_with($f->sti, '/bivrost/api/installasjon')) {
            // Ark-Ivars tillegg: appen viser installasjonssiden selv, så sidene utenfor /bivrost/ går til appen også
            // før installasjonen. Appen må selv sjekke installert() før den bruker databasen.
            if ($app !== null && in_array($f->metode, ['GET', 'HEAD'], true) && !str_starts_with($f->sti, '/bivrost/')) {
                $svar = $app($f, $this);
                if ($svar !== null) {
                    return $svar;
                }
            }
            throw new HttpFeil(503, 'ikke_installert', 'bivrost.feil.installasjon.ikke_installert');
        }
        $inst->sikreKode();
        $this->kontekst->settAktor(Aktor::system($this->tekster()->tekst('bivrost.aktor.installasjon', [], $this->app->hovedsprak)));
        if ($f->sti === '/bivrost/api/installasjon' && $f->metode === 'GET') {
            if ($f->hode('x-bivrost-installasjonskode') === null) {
                return Svar::json(['installert' => false, 'kode_kreves' => true]);
            }
            $inst->krevKode($f);
            $steg = 'database';
            try {
                if ($this->env()->hent('DB_NAVN') !== null) {
                    $this->db();
                    $steg = 'bruker';
                }
            } catch (BivrostFeil) {
                $steg = 'database';
            }

            return Svar::json(['installert' => false, 'steg' => $steg, 'forutsetninger' => $inst->forutsetninger()]);
        }
        if ($f->metode !== 'POST') {
            throw new HttpFeil(405, 'metode_ikke_tillatt', 'bivrost.feil.http.metode');
        }
        $inst->krevKode($f);
        foreach ($inst->forutsetninger() as $x) {
            if ($x['kreves'] && !$x['ok']) {
                throw new HttpFeil(422, 'forutsetning', 'bivrost.feil.installasjon.forutsetning', ['id' => $x['id']]);
            }
        }
        $totp = new Totp($this->klokke);

        return match ($f->sti) {
            '/bivrost/api/installasjon/database' => Svar::json(['ok' => true] + array_diff_key($inst->database($f->json(), $this->env()), ['env' => true])),
            '/bivrost/api/installasjon/bruker' => Svar::json($inst->bruker($f->json(), $this->envPaNytt(), $totp)),
            '/bivrost/api/installasjon/fullfor' => $this->fullforInstallasjon($f, $inst, $totp),
            default => throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet'),
        };
    }

    private function envPaNytt(): Env
    {
        $this->env = null;

        return $this->env();
    }

    private function fullforInstallasjon(Foresporsel $f, Installasjon $inst, Totp $totp): Svar
    {
        $env = $this->envPaNytt();
        $this->db = null;
        $r = $inst->fullfor((string) $f->tekst('kode', 20), $this->db(), $env, $totp);
        $this->logg()->skriv(
            'installasjon:fullfort',
            new LoggObjekt(null, 'lokal_bruker', $this->tekster()->tekst('bivrost.objekttype.lokal_bruker'), $r['bruker_id'], $r['bruker_id']),
            [],
            ['tilgang' => ['kode' => Urn::DRIFT, 'tekst' => $this->logg()->klartekst()->urn(Urn::DRIFT)['navn']]]
        );

        return Svar::json(['ok' => true, 'gjenopprettingskoder' => $r['gjenopprettingskoder']]);
    }

    // ---------------------------------------------------------------- Kjernens ruter

    private function kjerneruter(Okt $okt): Ruter
    {
        $r = new Ruter();
        $r->legg('GET', '/bivrost/api/okt', fn (): Svar => Svar::json($this->oktSvar($okt)));
        $r->legg('POST', '/bivrost/api/innlogging/lokal', function (Foresporsel $f) use ($okt): Svar {
            $this->lokal()->passord($okt, (string) $f->tekst('brukernavn', 200), (string) $f->tekst('passord', 1000), $f->ip);

            return Svar::json(['steg' => 'totp', 'csrf' => Csrf::nokkel($okt)]);
        });
        $r->legg('POST', '/bivrost/api/innlogging/lokal/totp', function (Foresporsel $f) use ($okt): Svar {
            $this->lokal()->totp($okt, (string) $f->tekst('kode', 40), $f->ip);
            $ny = $okt->innlogget();
            $svar = $this->oktSvar($okt);
            if ($ny !== null) {
                $this->kontekst->settAktor($ny->aktor);
                // false etter en innlogging med gjenopprettingskode når TOTP ikke kan leses (nøkkelen er byttet):
                // brukeren må sette ny TOTP (POST /bivrost/api/meg/totp).
                $b = (new LokalBrukere($this->db(), $this->boks()))->hent((string) $ny->aktor->id);
                $svar['totp_lesbar'] = $b !== null && (new LokalBrukere($this->db(), $this->boks()))->totpLesbar($b);
            }

            return Svar::json($svar);
        });
        $r->legg('POST', '/bivrost/api/utlogging', function () use ($okt): Svar {
            $i = $okt->innlogget();
            $adresse = null;
            if ($i !== null) {
                $this->logg()->skriv('utlogging:lykkes', null, [], [], ['metode' => $i->metode], 'sikkerhet');
                if ($i->metode === 'oidc') {
                    $adresse = $this->oidc()?->utloggingsadresse($this->adresse() . '/', null);
                }
            }
            $okt->loggUt();

            return Svar::json(['ok' => true, 'adresse' => $adresse]);
        });
        $r->legg('GET', '/bivrost/innlogging', fn (Foresporsel $f): Svar => $this->oidcStart($f, $okt));
        $r->legg('GET', '/bivrost/innlogging/tilbake', fn (Foresporsel $f): Svar => $this->oidcTilbake($f, $okt));
        $r->legg('GET', '/bivrost/api/meg/preferanser', fn (): Svar => $this->preferanseSvar($okt));
        $r->legg('PUT', '/bivrost/api/meg/preferanser', fn (Foresporsel $f): Svar => $this->endrePreferanser($f, $okt));
        $r->legg('POST', '/bivrost/api/meg/passord', function (Foresporsel $f) use ($okt): Svar {
            $i = $this->rbac()->krev($okt->innlogget(), Urn::DRIFT);
            $this->lokal()->byttPassord($okt, $i, (string) $f->tekst('gammelt', 1000), (string) $f->tekst('nytt', 1000), $f->ip);

            return Svar::json(['ok' => true]);
        });
        $r->legg('POST', '/bivrost/api/meg/totp', function (Foresporsel $f) use ($okt): Svar {
            $i = $this->rbac()->krev($okt->innlogget(), Urn::DRIFT);

            return Svar::json($this->lokal()->nyTotp($okt, $i, (string) $f->tekst('passord', 1000), $this->app->appnavn, $f->ip));
        });
        $r->legg('POST', '/bivrost/api/meg/totp/bekreft', function (Foresporsel $f) use ($okt): Svar {
            $i = $this->rbac()->krev($okt->innlogget(), Urn::DRIFT);

            $koder = $this->lokal()->bekreftTotp($okt, $i, (string) $f->tekst('kode', 40), $f->ip);

            // Økten har fått ny ID og ny CSRF-nøkkel (som ved innlogging).
            return Svar::json(['ok' => true, 'gjenopprettingskoder' => $koder, 'csrf' => Csrf::nokkel($okt)]);
        });
        $drift = fn (): Innlogget => $this->rbac()->krev($okt->innlogget(), Urn::DRIFT);
        $r->legg('GET', '/bivrost/api/system/oppdatering', function () use ($drift): Svar {
            $drift();
            $s = $this->oppdaterer()->status(true);
            $i = $this->oppdateringsApi()->info();
            $s['api_nokkel'] = $i === null ? null : ['navn' => $i['navn'], 'laget' => $i['laget'], 'laget_av' => $i['laget_av']];
            $s['punkter'] = $this->tilbakerulling()->punkter();
            if (is_string($s['plan_feil'] ?? null)) {
                $verdier = [];
                foreach (is_array($s['plan_feil_verdier'] ?? null) ? $s['plan_feil_verdier'] : [] as $navn => $verdi) {
                    if (is_string($navn) && (is_scalar($verdi) || $verdi === null)) {
                        $verdier[$navn] = $verdi;
                    }
                }
                $s['plan_feil_tekst'] = $this->tekster()->tekst($s['plan_feil'], $verdier, $this->sprak);
            }

            return Svar::json($s);
        });
        $r->legg('POST', '/bivrost/api/system/oppdatering/steg', function () use ($drift): Svar {
            $drift();

            return Svar::json($this->oppdaterer()->kjor(false));
        });
        $r->legg('POST', '/bivrost/api/system/oppdatering/slipp', function () use ($drift): Svar {
            $drift();
            $this->oppdaterer()->holdIgjen(false);
            $this->logg()->skriv('oppdatering:sluppet');

            return Svar::json(['ok' => true]);
        });
        $r->legg('POST', '/bivrost/api/system/oppdatering/nokkel', function (Foresporsel $f) use ($drift): Svar {
            $drift();
            $n = $this->oppdateringsApi()->lagNokkel((string) $f->tekst('navn', 100));

            return Svar::json(['nokkel' => $n]);
        });
        $r->legg('DELETE', '/bivrost/api/system/oppdatering/nokkel', function () use ($drift): Svar {
            $drift();
            $this->oppdateringsApi()->trekkTilbake();

            return Svar::json(['ok' => true]);
        });
        $r->legg('GET', '/bivrost/api/system/sikkerhetskopier', function () use ($drift): Svar {
            $drift();

            return Svar::json(['kopier' => $this->kopilager()->liste(), 'punkter' => $this->tilbakerulling()->punkter()]);
        });
        $r->legg('POST', '/bivrost/api/system/sikkerhetskopier', function () use ($drift): Svar {
            $drift();
            $db = $this->dbKopi()->ta();
            $filer = $this->filKopi()->ta(array_map(static fn (Pakke $p): string => $p->mappe, $this->pakker()));
            $this->logg()->skriv('drift:sikkerhetskopi', null, [], [], ['db_kopi' => $db, 'fil_kopi' => $filer]);

            return Svar::json(['db' => $db, 'filer' => $filer]);
        });
        $r->legg('GET', '/bivrost/api/system/sikkerhetskopier/{navn}', function (Foresporsel $f, array $v) use ($drift): Svar {
            $drift();
            $sti = $this->kopilager()->sti($v['navn'] ?? '');
            if (!is_file($sti)) {
                throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            }
            $this->logg()->skriv('drift:nedlasting', null, [], [], ['fil' => $v['navn'] ?? ''], 'revisjon');

            return Svar::fil($sti, $v['navn'] ?? 'kopi', 'application/octet-stream');
        });
        $r->legg('POST', '/bivrost/api/system/tilbakerulling', function (Foresporsel $f) use ($drift): Svar {
            $drift();

            return Svar::json($this->tilbakerulling()->tilbakerull((string) $f->tekst('punkt', 100)));
        });
        $r->legg('GET', '/bivrost/api/system/eksport', function () use ($drift): Svar {
            $drift();
            $versjoner = [];
            foreach ($this->pakker() as $p) {
                $i = $this->oppdaterer()->installert($p);
                $versjoner[$p->id] = is_string($i['versjon'] ?? null) ? $i['versjon'] : null;
            }
            $fil = (new Eksport($this->app, $this->logg(), $this->roller(), $this->kontekst, $this->eksport, $versjoner))->lag();

            return Svar::fil($fil, $this->app->app . '-eksport-' . gmdate('Ymd-His') . '.json', 'application/json', true);
        });
        $r->legg('GET', '/bivrost/api/system/logg', function (Foresporsel $f) use ($okt): Svar {
            $i = $okt->innlogget();
            if (!$this->rbac()->har($i, Urn::DRIFT) && !$this->rbac()->har($i, Urn::appTilgang($this->app->app, 'brukeradministrator'))) {
                $this->rbac()->krev($i, Urn::DRIFT);
            }
            $q = static fn (string $k): ?string => isset($f->query[$k]) && $f->query[$k] !== '' ? $f->query[$k] : null;
            try {
                $filter = new LoggFilter($q('app'), $q('bruker'), $q('fra'), $q('til'), $q('type'), $q('korrelasjon'), $q('kategori'));
            } catch (\InvalidArgumentException) {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'filter']);
            }
            $for = $q('for');
            $rader = $this->logg()->lager()->les($filter, 200, $for !== null && ctype_digit($for) ? (int) $for : null);

            return Svar::json([
                'hendelser' => array_map(static fn (array $x): array => ['nr' => $x['nr']] + $x['hendelse']->tilListe(), $rader),
                'neste' => count($rader) === 200 ? $rader[199]['nr'] : null,
            ]);
        });
        $r->legg('GET', '/bivrost/api/system/logg.jsonl', function (Foresporsel $f) use ($drift): Svar {
            $drift();
            $q = static fn (string $k): ?string => isset($f->query[$k]) && $f->query[$k] !== '' ? $f->query[$k] : null;
            try {
                $filter = new LoggFilter($q('app'), $q('bruker'), $q('fra'), $q('til'), $q('type'), $q('korrelasjon'), $q('kategori'));
            } catch (\InvalidArgumentException) {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'filter']);
            }
            $fil = $this->logg()->eksporterJsonl($this->app->drift . '/eksport', $filter, ($f->query['format'] ?? '') === 'cloudevents');

            return Svar::fil($fil, $this->app->app . '-logg-' . gmdate('Ymd-His') . '.jsonl', 'application/jsonl', true);
        });
        $r->legg('GET', '/bivrost/api/system/rolleregister', function () use ($okt): Svar {
            $i = $okt->innlogget();
            if (!$this->rbac()->har($i, Urn::BRUKERADMINISTRATOR)) {
                $this->rbac()->krev($i, Urn::DRIFT);
            }

            return Svar::json($this->roller()->dokument());
        });

        return $r;
    }

    private function tilbakerulling(): Tilbakerulling
    {
        return new Tilbakerulling(
            $this->app->drift,
            $this->db(),
            $this->logg(),
            $this->dbKopi(),
            $this->filKopi(),
            $this->kopilager(),
            $this->oppdaterer(),
            new Utgivernokler($this->db(), $this->klokke),
            $this->app->loggtabeller,
        );
    }

    /** Nettstedets adresse fra .env (aldri fra Host-hodet, så den ikke kan styres av den som spør). */
    private function adresse(): string
    {
        return rtrim($this->env()->hent('BIVROST_ADRESSE', '') ?? '', '/');
    }

    /** @return array<string, mixed> */
    private function oktSvar(Okt $okt): array
    {
        $i = $okt->innlogget();
        $kt = $this->logg()->klartekst();
        $ut = [
            'innlogget' => null,
            'csrf' => Csrf::nokkel($okt),
            'metoder' => ['sentral' => $this->oidc() !== null, 'lokal' => true, 'app' => $this->appbrukere !== null],
        ];
        if ($i !== null) {
            $eff = $this->rbac()->effektive($i->urner);
            $ut['innlogget'] = [
                'type' => $i->aktor->type,
                'type_tekst' => $kt->kode('aktortype', $i->aktor->type)['tekst'],
                'id' => $i->aktor->id,
                'navn' => $i->aktor->navn,
                'tittel' => $i->aktor->tittel,
                'metode' => $i->metode,
                'tilganger' => $kt->urner($eff),
            ];
        }

        return $ut;
    }

    private function oidcStart(Foresporsel $f, Okt $okt): Svar
    {
        $k = $this->oidc() ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.oidc.ikke_satt_opp');
        $retur = $f->query['retur'] ?? '/';
        if (preg_match('#^/(?!/)[A-Za-z0-9/._~%-]*$#D', $retur) !== 1) {
            $retur = '/';
        }
        try {
            $s = $k->start();
        } catch (OidcFeil $e) {
            $this->logg()->skrivTrygt('innlogging:avvist', null, ['metode' => 'oidc', 'grunn' => $e->tekstnokkel]);
            throw new HttpFeil(503, 'sentral_utilgjengelig', 'bivrost.feil.oidc.utilgjengelig');
        }
        $okt->sett('oidc', $s->tilstand + ['retur' => $retur]);

        return Svar::videre($s->adresse, 302);
    }

    private function oidcTilbake(Foresporsel $f, Okt $okt): Svar
    {
        $k = $this->oidc() ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.oidc.ikke_satt_opp');
        $t = $okt->hent('oidc');
        $okt->fjern('oidc');
        if (!is_array($t) || !is_string($t['state'] ?? null) || !is_string($t['nonce'] ?? null) || !is_string($t['verifikator'] ?? null) || !is_int($t['tid'] ?? null)) {
            throw new HttpFeil(400, 'oidc_tilstand', 'bivrost.feil.oidc.tilstand');
        }
        try {
            $id = $k->fullfor($f->query, ['state' => $t['state'], 'nonce' => $t['nonce'], 'verifikator' => $t['verifikator'], 'tid' => $t['tid']]);
        } catch (OidcFeil $e) {
            $this->logg()->skrivTrygt('innlogging:avvist', null, ['metode' => 'oidc', 'grunn' => $e->tekstnokkel, 'ip' => $f->ip]);
            throw new HttpFeil(401, 'innlogging_feil', $e->tekstnokkel === 'bivrost.feil.oidc.utilgjengelig' ? 'bivrost.feil.oidc.utilgjengelig' : 'bivrost.feil.oidc.feil');
        }
        $aktor = Aktor::bruker($id->sub, $id->navn, $id->tittel);
        $brudd = $this->rbac()->brudd($id->urner);
        $i = new Innlogget($aktor, $id->urner, 'oidc', $this->klokke->now()->getTimestamp(), $id->sid, 0, $id->epostBekreftet ? $id->epost : null);
        $okt->loggInn($i);
        if ($id->tilgangstoken !== null) {
            $okt->sett('oidc_token', $this->boks()->krypter($id->tilgangstoken, 'oidc_token'));
        }
        $this->kontekst->settAktor($aktor);
        $tilganger = $this->logg()->klartekst()->urner($this->rbac()->effektive($id->urner));
        $this->logg()->skriv('innlogging:lykkes', null, [], [], ['metode' => 'oidc', 'ip' => $f->ip, 'tilganger' => $tilganger], 'sikkerhet');
        foreach ($brudd as $b) {
            $this->logg()->skriv('tilgang:separasjon_brudd', null, [], [], ['regel' => $b['navn'], 'urner' => $this->logg()->klartekst()->urner($b['urner'])], 'sikkerhet');
        }
        $this->preferanser()->fraInnlogging($id->sub, $id->preferanser, $id->tilgangstoken);
        $retur = is_string($t['retur'] ?? null) ? $t['retur'] : '/';

        return Svar::videre($retur, 303);
    }

    private function backchannel(Foresporsel $f): Svar
    {
        $k = $this->oidc();
        parse_str($f->kropp, $kropp);
        $token = $kropp['logout_token'] ?? null;
        if ($k === null || !is_string($token)) {
            return Svar::json(['error' => 'invalid_request'], 400);
        }
        try {
            $u = $k->backchannel($token);
        } catch (OidcFeil) {
            $this->logg()->skrivTrygt('utlogging:avvist', null, ['metode' => 'backchannel', 'ip' => $f->ip]);

            return Svar::json(['error' => 'invalid_request'], 400);
        }
        try {
            $this->db()->utfor('INSERT INTO bivrost_jti (jti_hash, utloper) VALUES (?, ?)', [hash('sha256', $u['jti']), $u['utloper']]);
        } catch (\PDOException) {
            return Svar::json(['error' => 'invalid_request'], 400);
        }
        $n = $this->okt()->avsluttOidc($u['sid'], $u['sub']);
        $this->kontekst->settKilde('api');
        $this->kontekst->settAktor(Aktor::maskin($this->tekster()->tekst('bivrost.aktor.sentral', [], $this->app->hovedsprak)));
        $this->logg()->skriv('utlogging:backchannel', null, [], [], ['sid' => $u['sid'], 'sub' => $u['sub'], 'okter' => $n], 'sikkerhet');

        return new Svar(200, '', ['Cache-Control' => 'no-store']);
    }

    private function preferanseSvar(Okt $okt): Svar
    {
        $i = $okt->innlogget() ?? throw new HttpFeil(401, 'ikke_innlogget', 'bivrost.feil.tilgang.innlogging');
        $n = $this->prefNokkel($i);
        $p = $this->preferanser();
        $l = $p->lagret($n);

        return Svar::json([
            'effektive' => $p->effektive($n)->tilListe(),
            'felles' => $l['felles']->tilListe(),
            'app' => $l['app']->tilListe(),
            'kilder' => $p->kilder($n),
            'synkronisert' => $l['synkronisert'],
            'verdier' => Preferanser::VERDIER,
        ]);
    }

    private function endrePreferanser(Foresporsel $f, Okt $okt): Svar
    {
        $i = $okt->innlogget() ?? throw new HttpFeil(401, 'ikke_innlogget', 'bivrost.feil.tilgang.innlogging');
        $d = $f->json();
        $les = static function (mixed $x): array {
            if ($x === null) {
                return [];
            }
            if (!is_array($x)) {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'preferanser']);
            }
            $ut = [];
            foreach ($x as $k => $v) {
                if (!is_string($k) || ($v !== null && !is_string($v))) {
                    throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => (string) $k]);
                }
                $ut[$k] = $v;
            }

            return $ut;
        };
        $felles = $les($d['felles'] ?? null);
        $appValg = $les($d['app'] ?? null);
        $p = $this->preferanser();
        $n = $this->prefNokkel($i);
        $lagret = true;
        try {
            if ($felles !== []) {
                $token = $okt->hent('oidc_token');
                $tok = is_string($token) ? $this->boks()->dekrypter($token, 'oidc_token') : null;
                $lagret = $p->endreFelles($n, $felles, $i->metode === 'oidc' ? $tok : null);
            }
            if ($appValg !== []) {
                $p->endreApp($n, $appValg);
            }
        } catch (\InvalidArgumentException $e) {
            throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => $e->getMessage()]);
        }
        $svar = $this->preferanseSvar($okt);
        $svar->settHode('X-Bivrost-Sentral-Lagret', $lagret ? '1' : '0');

        return $svar;
    }
}
