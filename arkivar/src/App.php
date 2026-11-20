<?php

declare(strict_types=1);

namespace ArkIvar;

use ArkIvar\Arkiv\Arkivruter;
use ArkIvar\Brukere\Brukere;
use ArkIvar\Brukere\Domener;
use ArkIvar\Brukere\Engangskoder;
use ArkIvar\Brukere\Klasser;
use ArkIvar\Brukere\Konto;
use ArkIvar\Brukere\Roller;
use ArkIvar\Epost\Epostsender;
use ArkIvar\Epost\FilEpost;
use ArkIvar\Epost\MailEpost;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Csrf;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Ruter;
use Bivrost\Kjerne\Http\Sikkerhetshoder;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Innlogging\Passordhash;
use Bivrost\Kjerne\Innlogging\Passordregler;
use Bivrost\Kjerne\Kjerne;
use Bivrost\Kjerne\Klokke;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Bivrost\Kjerne\Okt\Innlogget;
use Bivrost\Kjerne\Tilgang\Urn;
use Psr\Clock\ClockInterface;

/**
 * Ark-Ivar: setter appens tjenester sammen med Bivrost Kjerne og har appens ruter under /api/. Kjerne tar seg av
 * HTTPS, økten, CSRF, sikkerhetshodene, feilsvarene og sine egne ruter under /bivrost/, og gir resten hit.
 *
 * Bruk (public/index.php):
 *
 *     $kjerne = Kjerne::fraKonfig(App::konfig());
 *     $app = new App($kjerne);
 *     $kjerne->handter(Foresporsel::fraGlobale(), $app->ruter(...))->send();
 */
final class App
{
    /** Navnet på den fiktive virksomheten studentene arbeider i. */
    public const VIRKSOMHET = 'Sirevåg kommune';

    private ?Brukere $brukere = null;

    /** @var (callable(): void)|null */
    private $vent;

    /**
     * @param Epostsender|null $epost Byttes ut i tester; ellers etter .env (epost()).
     * @param (callable(): void)|null $vent Forsinkelsen ved feil innlogging (byttes ut i tester).
     * @param string|null $grensesnittfil Siden grensesnittet starter fra; standard public/app/index.html (bygget av web/).
     */
    public function __construct(
        private readonly Kjerne $kjerne,
        private readonly ClockInterface $klokke = new Klokke(),
        private readonly ?Epostsender $epost = null,
        ?callable $vent = null,
        private readonly ?string $grensesnittfil = null,
    ) {
        $this->vent = $vent;
        // Kjerne spør om appens innlogginger fortsatt gjelder før rutene kjøres. Brukerne lages først da, så
        // forespørsler uten database (før installasjonen) ikke kobler til.
        $this->kjerne->settAppbrukere(new class ($this) implements \Bivrost\Kjerne\Okt\Appbrukere {
            public function __construct(private readonly App $app)
            {
            }

            public function gyldig(Innlogget $innlogget): bool
            {
                return $this->app->brukere()->gyldig($innlogget);
            }
        });
    }

    /**
     * Appens oppsett for Kjerne (bivrost.php) som en matrise med tekstnøkler.
     *
     * @return array<string, mixed>
     */
    public static function konfig(): array
    {
        $konfig = require dirname(__DIR__) . '/bivrost.php';
        $ut = [];
        foreach (is_array($konfig) ? $konfig : [] as $nokkel => $verdi) {
            $ut[(string) $nokkel] = $verdi;
        }

        return $ut;
    }

    public function brukere(): Brukere
    {
        return $this->brukere ??= new Brukere($this->kjerne->db(), $this->klokke);
    }

    private function domener(): Domener
    {
        return new Domener($this->kjerne->db(), $this->klokke);
    }

    private function klasser(): Klasser
    {
        return new Klasser($this->kjerne->db(), $this->klokke);
    }

    /**
     * Avsenderen av e-post. ARKIVAR_EPOST_FIL (en fil i driftsmappen) skriver brevene til fil i stedet for å sende
     * dem, og er bare for lokal utvikling. Ellers sendes de med mail() fra ARKIVAR_EPOST_FRA.
     */
    private function epost(): Epostsender
    {
        if ($this->epost !== null) {
            return $this->epost;
        }
        $env = $this->kjerne->env();
        $fil = $env->hent('ARKIVAR_EPOST_FIL');
        if ($fil !== null && $fil !== '') {
            return new FilEpost($this->kjerne->app->drift . '/' . basename($fil));
        }

        return new MailEpost($env->krev('ARKIVAR_EPOST_FRA'), $this->kjerne->app->appnavn);
    }

    private function konto(): Konto
    {
        return new Konto(
            $this->brukere(),
            $this->domener(),
            $this->klasser(),
            new Engangskoder($this->kjerne->db(), $this->klokke),
            $this->epost(),
            $this->kjerne->forsok(),
            $this->kjerne->logg(),
            $this->kjerne->tekster(),
            $this->kjerne->app->appnavn,
            $this->vent,
        );
    }

    /**
     * Appens ruter. Gir null når ingen rute passer (Kjerne svarer da 404), bortsett fra sidene i grensesnittet:
     * alle andre GET-adresser enn /api/ gir index.html, og nettleseren viser riktig side.
     */
    public function ruter(Foresporsel $f, Kjerne $kjerne): ?Svar
    {
        $side = in_array($f->metode, ['GET', 'HEAD'], true) && !str_starts_with($f->sti, '/api/');
        if (!$kjerne->installert()) {
            // Før installasjonen finnes ingen database: bare grensesnittet, som da viser installasjonssiden.
            return $side ? $this->grensesnitt($f) : null;
        }
        $ruter = new Ruter();
        $this->kontoruter($ruter);
        $this->klasseruter($ruter);
        $this->adminruter($ruter);
        (new Arkivruter($this->kjerne, $this->klokke))->legg($ruter);
        $svar = $ruter->handter($f);
        if ($svar !== null) {
            return $svar;
        }

        return $side ? $this->grensesnitt($f) : null;
    }

    /** Plassholderen i index.html som byttes med engangsverdien for stilene (web/index.html). */
    private const NONCE_PLASSHOLDER = 'ARKIVAR-STIL-NONCE';

    /**
     * Siden grensesnittet starter fra (bygget av web/ til public/app/).
     *
     * Fluent UI lager stilene sine i nettleseren, i <style>-elementer. Kjernens CSP (style-src 'self') stopper
     * dem, så siden får en engangsverdi (nonce) som bare gjelder dette svaret: den står i CSP-hodet og i en
     * meta-tagg som grensesnittet leser og setter på stilelementene. Skript er fortsatt bare filer fra egen server.
     */
    private function grensesnitt(Foresporsel $f): ?Svar
    {
        $fil = $this->grensesnittfil ?? dirname(__DIR__) . '/public/app/index.html';
        $html = is_file($fil) ? file_get_contents($fil) : false;
        if ($html === false) {
            return null;
        }
        $nonce = Tilfeldig::token(16);
        $csp = str_replace("style-src 'self'", "style-src 'self' 'nonce-$nonce'", Sikkerhetshoder::CSP);

        return Svar::html(str_replace(self::NONCE_PLASSHOLDER, $nonce, $html))
            ->settHode('Cache-Control', 'no-store')
            ->settHode('Content-Security-Policy', $f->https ? $csp : str_replace('; upgrade-insecure-requests', '', $csp));
    }

    // ---------------------------------------------------------------- konto

    private function kontoruter(Ruter $r): void
    {
        $k = $this->kjerne;
        $r->legg('GET', '/api/oppsett', function () use ($k): Svar {
            return Svar::json([
                'appnavn' => $k->app->appnavn,
                'virksomhet' => self::VIRKSOMHET,
                'versjon' => trim((string) file_get_contents(dirname(__DIR__) . '/VERSJON')),
                'sprak' => $k->sprak(),
                'passord' => ['min' => Passordregler::MIN, 'maks' => Passordregler::MAKS],
                'steder' => array_map(
                    static fn (array $d): array => ['sted' => Felt::tekst($d['sted']), 'domene' => Felt::tekst($d['domene'])],
                    $this->domener()->liste(true),
                ),
            ]);
        });
        $r->legg('POST', '/api/registrering', function (Foresporsel $f) use ($k): Svar {
            $this->konto()->registrer(
                (string) $f->tekst('navn', 200),
                (string) $f->tekst('epost', 254),
                (string) $f->tekst('passord', 1000),
                $f->tekst('klassekode', 40, false),
                $f->ip,
                $k->sprak(),
            );

            return Svar::json(['steg' => 'bekreft']);
        });
        $r->legg('POST', '/api/registrering/bekreft', function (Foresporsel $f): Svar {
            $this->konto()->bekreft($this->kjerne->okt(), (string) $f->tekst('epost', 254), (string) $f->tekst('kode', 40), $f->ip);

            return $this->innloggetSvar();
        });
        $r->legg('POST', '/api/registrering/ny-kode', function (Foresporsel $f) use ($k): Svar {
            $this->konto()->nyBekreftelseskode((string) $f->tekst('epost', 254), $f->ip, $k->sprak());

            return Svar::json(['ok' => true]);
        });
        $r->legg('POST', '/api/innlogging', function (Foresporsel $f): Svar {
            $this->konto()->loggInn($this->kjerne->okt(), (string) $f->tekst('epost', 254), (string) $f->tekst('passord', 1000), $f->ip);

            return $this->innloggetSvar();
        });
        $r->legg('POST', '/api/passord/glemt', function (Foresporsel $f) use ($k): Svar {
            $this->konto()->glemtPassord((string) $f->tekst('epost', 254), $f->ip, $k->sprak());

            return Svar::json(['ok' => true]);
        });
        $r->legg('POST', '/api/passord/nytt', function (Foresporsel $f): Svar {
            $this->konto()->nyttPassord(
                $this->kjerne->okt(),
                (string) $f->tekst('epost', 254),
                (string) $f->tekst('kode', 40),
                (string) $f->tekst('passord', 1000),
                $f->ip,
            );

            return $this->innloggetSvar();
        });
        $r->legg('GET', '/api/meg', function () use ($k): Svar {
            $rad = $this->egenBruker($k->krev(Roller::urn(Roller::STUDENT)));

            return Svar::json([
                'bruker' => Brukere::offentlig($rad),
                'klasser' => array_map(static fn (array $kl): array => Klasser::offentlig($kl, false), $this->klasser()->forStudent(Felt::tekst($rad['id']))),
            ]);
        });
        $r->legg('PUT', '/api/meg', function (Foresporsel $f) use ($k): Svar {
            $rad = $this->egenBruker($k->krev(Roller::urn(Roller::STUDENT)));
            $navn = trim((string) $f->tekst('navn', 200));
            if (mb_strlen($navn, 'UTF-8') < 2) {
                throw new HttpFeil(422, 'ugyldig_navn', 'arkivar.feil.navn');
            }
            $this->brukere()->settNavn(Felt::tekst($rad['id']), $navn);

            return Svar::json(['ok' => true]);
        });
        $r->legg('POST', '/api/meg/passord', function (Foresporsel $f) use ($k): Svar {
            $innlogget = $k->krev(Roller::urn(Roller::STUDENT));
            $this->konto()->byttPassord($k->okt(), $innlogget, (string) $f->tekst('gammelt', 1000), (string) $f->tekst('nytt', 1000), $f->ip);

            // Økten har fått ny ID og ny CSRF-nøkkel (som ved innlogging).
            return Svar::json(['ok' => true, 'csrf' => Csrf::nokkel($k->okt())]);
        });
        $r->legg('POST', '/api/meg/klasser', function (Foresporsel $f) use ($k): Svar {
            $innlogget = $k->krev(Roller::urn(Roller::STUDENT));
            $id = (string) $innlogget->aktor->id;
            $k->forsok()->sjekk('arkivar_klassekode', [[$id, 10, 900]]);
            $klasse = $this->klasser()->medKode((string) $f->tekst('kode', 40));
            if ($klasse === null) {
                $k->forsok()->registrer('arkivar_klassekode', $id);
                throw new HttpFeil(422, 'ukjent_klassekode', 'arkivar.feil.klassekode');
            }
            if ($this->klasser()->meldInn(Felt::tekst($klasse['id']), $id)) {
                $k->logg()->skriv('urn:arkivar:hendelse:klasse:innmeldt', $this->klasseobjekt($klasse), [], [], [], 'revisjon');
            }

            return Svar::json(['klasse' => Klasser::offentlig($klasse, false)]);
        });
    }

    /** Svaret etter en innlogging: den samme formen som Kjernens GET /bivrost/api/okt, med ny CSRF-nøkkel. */
    private function innloggetSvar(): Svar
    {
        $k = $this->kjerne;
        $innlogget = $k->okt()->innlogget();
        $klartekst = $k->logg()->klartekst();

        return Svar::json([
            'csrf' => Csrf::nokkel($k->okt()),
            'innlogget' => $innlogget === null ? null : [
                'type' => $innlogget->aktor->type,
                'id' => $innlogget->aktor->id,
                'navn' => $innlogget->aktor->navn,
                'tittel' => $innlogget->aktor->tittel,
                'metode' => $innlogget->metode,
                'tilganger' => $klartekst->urner($k->rbac()->effektive($innlogget->urner)),
            ],
        ]);
    }

    /**
     * Raden for den innloggede. Driftsbrukeren fra nødinnloggingen har ingen rad her og ingen fagrolle, så den
     * kommer aldri hit (krev() stopper den).
     *
     * @return array<string, mixed>
     */
    private function egenBruker(Innlogget $innlogget): array
    {
        $rad = $innlogget->metode === 'app' ? $this->brukere()->hent((string) $innlogget->aktor->id) : null;

        return $rad ?? throw new HttpFeil(403, 'ingen_tilgang', 'bivrost.feil.tilgang.mangler');
    }

    // ---------------------------------------------------------------- klasser (lærer)

    private function klasseruter(Ruter $r): void
    {
        $k = $this->kjerne;
        $r->legg('GET', '/api/klasser', function () use ($k): Svar {
            $innlogget = $k->krev(Roller::urn(Roller::LAERER));
            $alle = $k->rbac()->har($innlogget, Roller::urn(Roller::ADMINISTRATOR));

            return Svar::json(['klasser' => array_map(
                static fn (array $kl): array => Klasser::offentlig($kl, true),
                $this->klasser()->liste($alle ? null : (string) $innlogget->aktor->id),
            )]);
        });
        $r->legg('POST', '/api/klasser', function (Foresporsel $f) use ($k): Svar {
            $innlogget = $k->krev(Roller::urn(Roller::LAERER));
            $navn = $this->klassenavn($f);
            $id = $this->klasser()->opprett($navn, (string) $innlogget->aktor->id);
            $klasse = $this->klasser()->hent($id) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern');
            $k->logg()->skriv('urn:arkivar:hendelse:klasse:opprettet', $this->klasseobjekt($klasse));

            return Svar::json(['klasse' => Klasser::offentlig($klasse, true)], 201);
        });
        $r->legg('GET', '/api/klasser/{id}', function (Foresporsel $f, array $v): Svar {
            $klasse = $this->egenKlasse($v['id']);

            return Svar::json([
                'klasse' => Klasser::offentlig($klasse, true),
                'studenter' => array_map(
                    static fn (array $b): array => ['id' => Felt::tekst($b['id']), 'navn' => Felt::tekst($b['navn']), 'epost' => Felt::tekst($b['epost']),
                        'innmeldt' => Brukere::tidspunkt($b['innmeldt']), 'sist_innlogget' => Brukere::tidspunkt($b['sist_innlogget'])],
                    $this->klasser()->medlemmer(Felt::tekst($klasse['id'])),
                ),
            ]);
        });
        $r->legg('PUT', '/api/klasser/{id}', function (Foresporsel $f, array $v) use ($k): Svar {
            $klasse = $this->egenKlasse($v['id']);
            $aktiv = $f->json()['aktiv'] ?? true;
            $this->klasser()->endre(Felt::tekst($klasse['id']), $this->klassenavn($f), $aktiv !== false);
            $k->logg()->skriv('urn:arkivar:hendelse:klasse:endret', $this->klasseobjekt($klasse), [], [], ['aktiv' => $aktiv !== false]);

            return Svar::json(['ok' => true]);
        });
        $r->legg('POST', '/api/klasser/{id}/ny-kode', function (Foresporsel $f, array $v) use ($k): Svar {
            $klasse = $this->egenKlasse($v['id']);
            $kode = $this->klasser()->nyKode(Felt::tekst($klasse['id']));
            $k->logg()->skriv('urn:arkivar:hendelse:klasse:ny_kode', $this->klasseobjekt($klasse));

            return Svar::json(['kode' => $kode]);
        });
        $r->legg('DELETE', '/api/klasser/{id}/studenter/{bruker}', function (Foresporsel $f, array $v) use ($k): Svar {
            $klasse = $this->egenKlasse($v['id']);
            $this->klasser()->meldUt(Felt::tekst($klasse['id']), $v['bruker']);
            $k->logg()->skriv('urn:arkivar:hendelse:klasse:utmeldt', $this->klasseobjekt($klasse), [], [], ['bruker' => $v['bruker']]);

            return Svar::json(['ok' => true]);
        });
    }

    private function klassenavn(Foresporsel $f): string
    {
        $navn = trim((string) $f->tekst('navn', 200));
        if (mb_strlen($navn, 'UTF-8') < 2) {
            throw new HttpFeil(422, 'ugyldig_navn', 'arkivar.feil.klassenavn');
        }

        return $navn;
    }

    /**
     * Klassen når den innloggede er læreren for den, eller administrator. Andres klasser finnes ikke for læreren
     * (404, så svaret ikke røper at ID-en er i bruk).
     *
     * @return array<string, mixed>
     */
    private function egenKlasse(string $id): array
    {
        $k = $this->kjerne;
        $innlogget = $k->krev(Roller::urn(Roller::LAERER));
        $klasse = $this->klasser()->hent($id);
        $egen = $klasse !== null && (Felt::tekst($klasse['laerer_id']) === $innlogget->aktor->id || $k->rbac()->har($innlogget, Roller::urn(Roller::ADMINISTRATOR)));

        return $klasse !== null && $egen ? $klasse : throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
    }

    /** @param array<string, mixed> $klasse */
    private function klasseobjekt(array $klasse): LoggObjekt
    {
        return new LoggObjekt(Roller::APP, 'klasse', $this->kjerne->logg()->tekst('arkivar.objekttype.klasse'), Felt::tekst($klasse['id']), Felt::tekst($klasse['navn']));
    }

    // ---------------------------------------------------------------- administrasjon

    /**
     * Brukere og godkjente domener styres av administratoren. Driftsbrukeren fra nødinnloggingen kan det samme, så
     * den første administratoren kan lages rett etter installasjonen.
     */
    private function krevAdministrator(): Innlogget
    {
        $k = $this->kjerne;
        $innlogget = $k->innlogget();
        if ($innlogget !== null && $k->rbac()->har($innlogget, Urn::DRIFT)) {
            return $innlogget;
        }

        return $k->krev(Roller::urn(Roller::ADMINISTRATOR));
    }

    private function adminruter(Ruter $r): void
    {
        $k = $this->kjerne;
        $r->legg('GET', '/api/admin/domener', function (): Svar {
            $this->krevAdministrator();

            return Svar::json(['domener' => array_map(Domener::offentlig(...), $this->domener()->liste())]);
        });
        $r->legg('POST', '/api/admin/domener', function (Foresporsel $f) use ($k): Svar {
            $this->krevAdministrator();
            $domene = Domener::normaliser((string) $f->tekst('domene', 253));
            $sted = trim((string) $f->tekst('sted', 200));
            if ($domene === null) {
                throw new HttpFeil(422, 'ugyldig_domene', 'arkivar.feil.domenenavn');
            }
            if ($sted === '') {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'sted']);
            }
            if ($this->domener()->finn($domene) !== null) {
                throw new HttpFeil(409, 'finnes', 'arkivar.feil.domene_finnes');
            }
            $id = $this->domener()->opprett($domene, $sted, ($f->json()['underdomener'] ?? true) !== false);
            $rad = $this->domener()->hent($id) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern');
            $k->logg()->skriv('urn:arkivar:hendelse:domene:opprettet', $this->domeneobjekt($rad), [], [], ['sted' => $sted]);

            return Svar::json(['domene' => Domener::offentlig($rad)], 201);
        });
        $r->legg('PUT', '/api/admin/domener/{id}', function (Foresporsel $f, array $v) use ($k): Svar {
            $this->krevAdministrator();
            $rad = $this->domener()->hent($v['id']) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $sted = trim((string) $f->tekst('sted', 200));
            if ($sted === '') {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'sted']);
            }
            $data = $f->json();
            $underdomener = ($data['underdomener'] ?? true) !== false;
            $aktiv = ($data['aktiv'] ?? true) !== false;
            $this->domener()->endre($v['id'], $sted, $underdomener, $aktiv);
            $k->logg()->skriv('urn:arkivar:hendelse:domene:endret', $this->domeneobjekt($rad), [], [], ['sted' => $sted, 'underdomener' => $underdomener, 'aktiv' => $aktiv]);

            return Svar::json(['ok' => true]);
        });
        $r->legg('DELETE', '/api/admin/domener/{id}', function (Foresporsel $f, array $v) use ($k): Svar {
            $this->krevAdministrator();
            $rad = $this->domener()->hent($v['id']) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $this->domener()->slett($v['id']);
            $k->logg()->skriv('urn:arkivar:hendelse:domene:slettet', $this->domeneobjekt($rad));

            return Svar::json(['ok' => true]);
        });
        $r->legg('GET', '/api/admin/brukere', function (Foresporsel $f): Svar {
            $this->krevAdministrator();

            return Svar::json(['brukere' => array_map(Brukere::offentlig(...), $this->brukere()->liste($f->query['sok'] ?? null))]);
        });
        $r->legg('POST', '/api/admin/brukere', function (Foresporsel $f) use ($k): Svar {
            $this->krevAdministrator();
            $epost = Brukere::normaliserEpost((string) $f->tekst('epost', 254));
            $navn = trim((string) $f->tekst('navn', 200));
            $rolle = (string) $f->tekst('rolle', 20);
            $passord = (string) $f->tekst('passord', 1000);
            if ($epost === null) {
                throw new HttpFeil(422, 'ugyldig_epost', 'arkivar.feil.epost');
            }
            if (mb_strlen($navn, 'UTF-8') < 2) {
                throw new HttpFeil(422, 'ugyldig_navn', 'arkivar.feil.navn');
            }
            if (!Roller::gyldig($rolle)) {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'rolle']);
            }
            $feil = Passordregler::feil($passord, [$epost, explode('@', $epost)[0], $navn, $k->app->appnavn]);
            if ($feil !== null) {
                throw new HttpFeil(422, 'passord_svakt', $feil);
            }
            if ($this->brukere()->finn($epost) !== null) {
                throw new HttpFeil(409, 'finnes', 'arkivar.feil.epost_finnes');
            }
            $id = $this->brukere()->opprett($epost, $navn, Passordhash::hash($passord), $rolle, 'administrator', true);
            $rad = $this->brukere()->hent($id) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern');
            $k->logg()->skriv('urn:arkivar:hendelse:bruker:opprettet', $this->brukerobjekt($rad), [], [], ['rolle' => $rolle], 'sikkerhet');

            return Svar::json(['bruker' => Brukere::offentlig($rad)], 201);
        });
        $r->legg('PUT', '/api/admin/brukere/{id}', function (Foresporsel $f, array $v) use ($k): Svar {
            $innlogget = $this->krevAdministrator();
            $rad = $this->brukere()->hent($v['id']) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $data = $f->json();
            $rolle = is_string($data['rolle'] ?? null) ? $data['rolle'] : Felt::tekst($rad['rolle']);
            $aktiv = array_key_exists('aktiv', $data) ? $data['aktiv'] !== false : Felt::tall($rad['aktiv']) === 1;
            if (!Roller::gyldig($rolle)) {
                throw new HttpFeil(422, 'ugyldig_felt', 'bivrost.feil.http.felt', ['felt' => 'rolle']);
            }
            // Den siste aktive administratoren kan ikke fjernes (heller ikke av seg selv); da kan ingen styre brukerne.
            $varAdmin = Felt::tekst($rad['rolle']) === Roller::ADMINISTRATOR && Felt::tall($rad['aktiv']) === 1;
            if ($varAdmin && ($rolle !== Roller::ADMINISTRATOR || !$aktiv) && $this->brukere()->antallAktive(Roller::ADMINISTRATOR) <= 1) {
                throw new HttpFeil(409, 'siste_administrator', 'arkivar.feil.siste_administrator');
            }
            $for = ['rolle' => $this->rollekode(Felt::tekst($rad['rolle'])), 'aktiv' => $k->logg()->klartekst()->kode('aktiv', Felt::tall($rad['aktiv']) === 1)];
            $etter = ['rolle' => $this->rollekode($rolle), 'aktiv' => $k->logg()->klartekst()->kode('aktiv', $aktiv)];
            $this->brukere()->settRolle($v['id'], $rolle);
            $this->brukere()->settAktiv($v['id'], $aktiv);
            if (!$aktiv) {
                $k->okt()->avsluttFor('bruker', $v['id']);
            }
            $k->logg()->skriv('urn:arkivar:hendelse:bruker:endret', $this->brukerobjekt($rad), $for, $etter, ['egen' => $innlogget->aktor->id === $v['id']], 'sikkerhet');

            return Svar::json(['ok' => true]);
        });
    }

    /** @return array{kode: string, tekst: string} */
    private function rollekode(string $rolle): array
    {
        return ['kode' => $rolle, 'tekst' => $this->kjerne->logg()->tekst('arkivar.rolle.' . $rolle)];
    }

    /** @param array<string, mixed> $rad */
    private function brukerobjekt(array $rad): LoggObjekt
    {
        return new LoggObjekt(Roller::APP, 'bruker', $this->kjerne->logg()->tekst('arkivar.objekttype.bruker'), Felt::tekst($rad['id']), Felt::tekst($rad['navn']));
    }

    /** @param array<string, mixed> $rad */
    private function domeneobjekt(array $rad): LoggObjekt
    {
        return new LoggObjekt(Roller::APP, 'domene', $this->kjerne->logg()->tekst('arkivar.objekttype.domene'), Felt::tekst($rad['id']), Felt::tekst($rad['domene']));
    }
}
