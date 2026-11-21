<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use ArkIvar\App;
use ArkIvar\Brukere\Roller;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Ruter;
use Bivrost\Kjerne\Http\Svar;
use Bivrost\Kjerne\Kjerne;
use Bivrost\Kjerne\Logg\LoggObjekt;
use Psr\Clock\ClockInterface;

/**
 * Rutene for øvingsarkivet under /api/arkiv/. Alle krever en innlogget bruker med rollen student (lærere og
 * administratorer har den også), og alt gjelder brukerens eget arkiv: arkivets ID kommer aldri fra nettleseren.
 *
 * Arbeidsgangen: studenten søker etter mappen (GET sok), oppretter den hvis den ikke finnes (POST saker), og
 * registrerer dokumentet som en journalpost i mappen (POST saker/{id}/journalposter). Dokumenter som ikke skal i
 * arkivet, får bare valget registrert (POST postmottak/{id}).
 */
final class Arkivruter
{
    private ?Grunndata $grunndata = null;
    private ?Oppgavesett $oppgaver = null;
    private ?Dokumentmaler $maler = null;
    private ?Skriving $skriving = null;

    public function __construct(private readonly Kjerne $kjerne, private readonly ClockInterface $klokke)
    {
    }

    private function grunndata(): Grunndata
    {
        return $this->grunndata ??= new Grunndata();
    }

    private function register(): Register
    {
        return new Register($this->kjerne->db(), $this->klokke, $this->grunndata());
    }

    private function ovingsarkiv(): Ovingsarkiv
    {
        return new Ovingsarkiv($this->kjerne->db(), $this->klokke, $this->grunndata(), $this->register(), $this->oppgaver());
    }

    private function oppgaver(): Oppgavesett
    {
        return $this->oppgaver ??= new Oppgavesett();
    }

    private function maler(): Dokumentmaler
    {
        return $this->maler ??= new Dokumentmaler();
    }

    /** Den samme i hele forespørselen: den husker hvilke journalposter som har et skrevet dokument. */
    private function skriving(): Skriving
    {
        return $this->skriving ??= new Skriving($this->kjerne->db(), $this->klokke, $this->grunndata(), $this->maler(), $this->register());
    }

    private function kontroll(): Kontroll
    {
        return new Kontroll($this->kjerne->db(), $this->klokke, $this->register());
    }

    private function mapper(): Mapper
    {
        return new Mapper($this->kjerne->db(), $this->klokke, $this->grunndata(), $this->register());
    }

    /** Loggobjektet for en mappe eller journalpost: typen og nummeret, ikke tittelen. */
    private function loggobjekt(string $type, string $id, string $nummer): LoggObjekt
    {
        return new LoggObjekt(Roller::APP, $type, $this->kjerne->logg()->tekst('arkivar.objekttype.' . $type), $id, $nummer);
    }

    private function postmottak(): Postmottak
    {
        return new Postmottak($this->kjerne->db(), $this->klokke, $this->grunndata(), $this->oppgaver(), $this->register(), $this->skriving());
    }

    /**
     * Dokumentet til en journalpost: fra postmottaket når studenten har registrert det, ellers dokumentet som fulgte
     * med arkivet, eller det studenten har skrevet selv. Med $versjon gis den versjonen av dokumentet (bare skrevne
     * dokumenter og dokumenter med utkast har flere). Null når posten ikke har noe dokument.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    private function dokument(string $arkivId, array $post, ?int $versjon = null): ?array
    {
        $saksnumre = $this->register()->saksnumre($arkivId);
        if (is_string($post['oppgave_id'])) {
            $oppgave = $this->oppgaver()->hent($post['oppgave_id']);

            return $oppgave !== null ? Grunndata::medSaksnumre(Oppgavesett::dokument($oppgave), $saksnumre) : null;
        }
        if (!is_string($post['startdokument'])) {
            // Ingen av delene: dokumentet er skrevet av studenten, eller journalposten har ikke noe dokument.
            return $this->skriving()->dokument($arkivId, $post, $versjon);
        }
        $dokument = $this->grunndata()->startdokument($post['startdokument']);
        if ($dokument === null) {
            return null;
        }
        if ($versjon === 1 && $dokument['utkast'] !== []) {
            // Versjon 1 av et dokument med to versjoner er saksbehandlerens utkast, uten vedleggene.
            $dokument = ['tekst' => $dokument['utkast'], 'vedlegg' => []] + $dokument;
        }

        return Grunndata::medSaksnumre($dokument, $saksnumre, explode('#', $post['startdokument'])[0]);
    }

    /**
     * Journalposten etter at dokumentet i den er opprettet, lagret eller ferdigstilt, og hendelsen i loggen
     * (løpenummeret, ikke tittelen). Lagring av et utkast logges ikke.
     *
     * @return array<string, mixed>
     */
    private function skrevet(string $arkivId, string $id, ?string $hendelse): array
    {
        $rad = $this->register()->journalpost($arkivId, $id) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern');
        $post = $this->postmottak()->visJournalpost($rad);
        if ($hendelse !== null) {
            $this->kjerne->logg()->skriv($hendelse, $this->loggobjekt('journalpost', $id, Felt::tekst($post['lopenummer'])), [], [], ['mal' => $post['mal']]);
        }

        return $post;
    }

    /**
     * Mappen etter at arkivtjenesten har kontrollert eller avsluttet den, og hendelsen i loggen (saksnummeret).
     *
     * @return array<string, mixed>
     */
    private function mappeEtter(string $arkivId, string $id, string $hendelse): array
    {
        $register = $this->register();
        $sak = $register->visSak($register->sak($arkivId, $id) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern'));
        $this->kjerne->logg()->skriv($hendelse, $this->loggobjekt('mappe', $id, Felt::tekst($sak['saksnummer'])));

        return $sak;
    }

    /** ID-en til arkivet til den innloggede. Arkivet lages første gang det åpnes. */
    private function arkiv(): string
    {
        $innlogget = $this->kjerne->krev(Roller::urn(Roller::STUDENT));
        if ($innlogget->metode !== 'app' || $innlogget->aktor->id === null) {
            throw new HttpFeil(403, 'ingen_tilgang', 'bivrost.feil.tilgang.mangler');
        }

        return $this->ovingsarkiv()->forBruker($innlogget->aktor->id);
    }

    public function legg(Ruter $r): void
    {
        $r->legg('GET', '/api/arkiv', function (): Svar {
            $arkiv = $this->arkiv();
            $postmottak = $this->postmottak()->liste($arkiv);

            return Svar::json([
                'virksomhet' => $this->grunndata()->virksomhet(),
                'oversikt' => $this->ovingsarkiv()->oversikt($arkiv) + ['dokumenter' => count($postmottak)],
            ]);
        });
        $r->legg('GET', '/api/arkiv/struktur', function (): Svar {
            $this->kjerne->krev(Roller::urn(Roller::STUDENT));
            $g = $this->grunndata();

            $registre = [];
            foreach ($g->arkivdeler() as $del) {
                if ($del['ordning'] !== Grunndata::EMNE) {
                    $registre[$del['kode']] = $g->register($del['kode']);
                }
            }

            return Svar::json([
                'arkivdeler' => $g->arkivdeler(), 'klasser' => $g->klasser(), 'registre' => $registre, 'saksmaler' => $g->saksmaler(), 'enheter' => $g->enheter(),
                'ansatte' => $g->ansatte(), 'tilgangskoder' => $g->tilgangskoder(), 'dokumentmaler' => $this->maler()->alle(),
                'journalposttyper' => Kodelister::JOURNALPOSTTYPER, 'handlinger' => Kodelister::HANDLINGER,
                // Statuskodene fra Noark 5 for mappen, journalposten og dokumentfilen. Navnene står i språkfilene.
                'saksstatuser' => Kodelister::SAKSSTATUSER, 'journalstatuser' => Kodelister::JOURNALSTATUSER,
                'dokumentstatuser' => Kodelister::DOKUMENTSTATUSER, 'variantformater' => Kodelister::VARIANTFORMATER,
            ]);
        });
        $r->legg('GET', '/api/arkiv/postmottak', fn (): Svar => Svar::json(['dokumenter' => $this->postmottak()->liste($this->arkiv())]));
        $r->legg('GET', '/api/arkiv/postmottak/{id}', fn (Foresporsel $f, array $v): Svar => Svar::json($this->postmottak()->vis($this->arkiv(), $v['id'])));
        $r->legg('POST', '/api/arkiv/postmottak/{id}', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $svar = $this->postmottak()->registrer($arkiv, $v['id'], $f->json());
            $this->kjerne->logg()->skriv(
                'urn:arkivar:hendelse:postmottak:behandlet',
                $this->loggobjekt('dokument', $v['id'], $v['id']),
                [],
                [],
                ['handling' => $svar['handling'] ?? null, 'poeng' => $svar['poeng'] ?? null, 'maks' => $svar['maks'] ?? null],
            );

            return Svar::json($svar, 201);
        });
        $r->legg('DELETE', '/api/arkiv/postmottak/{id}', function (Foresporsel $f, array $v): Svar {
            $this->postmottak()->angre($this->arkiv(), $v['id']);

            return Svar::json(['ok' => true]);
        });
        $r->legg('GET', '/api/arkiv/saker', function (): Svar {
            $arkiv = $this->arkiv();
            $register = $this->register();

            return Svar::json(['saker' => array_map($register->visSak(...), $register->saker($arkiv))]);
        });
        // Søket studenten bruker for å finne ut om mappen finnes fra før.
        $r->legg('GET', '/api/arkiv/sok', function (Foresporsel $f): Svar {
            $arkiv = $this->arkiv();
            // Søket sendes tilbake i svaret: ugyldig tekst (ikke UTF-8) kan ikke kodes som JSON, og blir et tomt søk.
            $sok = Felt::tekst($f->query['q'] ?? null);
            $sok = mb_check_encoding($sok, 'UTF-8') ? mb_substr($sok, 0, 200, 'UTF-8') : '';

            return Svar::json(['sok' => $sok, 'treff' => $this->register()->sok($arkiv, $sok)]);
        });
        $r->legg('POST', '/api/arkiv/saker', function (Foresporsel $f): Svar {
            $arkiv = $this->arkiv();
            $register = $this->register();
            $id = $this->mapper()->opprett($arkiv, $f->json());
            $sak = $register->visSak($register->sak($arkiv, $id) ?? throw new HttpFeil(500, 'intern_feil', 'bivrost.feil.intern'));
            $objekt = $this->loggobjekt('mappe', $id, Felt::tekst($sak['saksnummer']));
            $this->kjerne->logg()->skriv('urn:arkivar:hendelse:mappe:opprettet', $objekt, [], [], ['arkivdel' => $sak['arkivdel'], 'mal' => $sak['mal']]);

            return Svar::json(['sak' => $sak], 201);
        });
        $r->legg('GET', '/api/arkiv/saker/{id}', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $register = $this->register();
            $postmottak = $this->postmottak();
            $sak = $register->sak($arkiv, $v['id']) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $poster = $register->journalposter($arkiv, $v['id']);

            return Svar::json([
                'sak' => $register->visSak($sak),
                'journalposter' => array_map(static fn (array $p): array => $postmottak->visJournalpost($p), $poster),
                // En tom mappe studenten har opprettet selv, kan slettes.
                'kan_slettes' => $sak['nokkel'] === null && $poster === [],
            ]);
        });
        $r->legg('DELETE', '/api/arkiv/saker/{id}', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $sak = $this->register()->sak($arkiv, $v['id']);
            $this->mapper()->slett($arkiv, $v['id']);
            $nummer = $sak !== null ? Felt::tall($sak['saksaar']) . '/' . Felt::tall($sak['sekvens']) : $v['id'];
            $this->kjerne->logg()->skriv('urn:arkivar:hendelse:mappe:slettet', $this->loggobjekt('mappe', $v['id'], $nummer));

            return Svar::json(['ok' => true]);
        });
        // En journalpost i mappen: et dokument fra postmottaket (da vurderes registreringen), eller studentens egen.
        $r->legg('POST', '/api/arkiv/saker/{id}/journalposter', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $svar = $this->postmottak()->journalpost($arkiv, $v['id'], $f->json());
            $post = $svar['journalpost'];
            if ($svar['oppgave'] !== null) {
                $this->kjerne->logg()->skriv(
                    'urn:arkivar:hendelse:postmottak:behandlet',
                    $this->loggobjekt('dokument', $svar['oppgave'], $svar['oppgave']),
                    [],
                    [],
                    ['handling' => $post['journalfort'] === true ? Kodelister::JOURNALFOR : Kodelister::ARKIVER, 'poeng' => $svar['poeng'], 'maks' => $svar['maks']],
                );
            } else {
                $this->kjerne->logg()->skriv('urn:arkivar:hendelse:journalpost:registrert', $this->loggobjekt('journalpost', Felt::tekst($post['id']), Felt::tekst($post['lopenummer'])));
            }

            return Svar::json($svar, 201);
        });
        $r->legg('GET', '/api/arkiv/journalposter/{id}', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $register = $this->register();
            $post = $register->journalpost($arkiv, $v['id']) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $sak = $register->sak($arkiv, Felt::tekst($post['sak_id'])) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');

            // Lesevinduet ber om en bestemt versjon av dokumentet. Uten gis den gjeldende.
            $versjon = isset($f->query['versjon']) ? max(1, (int) Felt::tekst($f->query['versjon'])) : null;

            return Svar::json(['journalpost' => $this->postmottak()->visJournalpost($post), 'sak' => $register->visSak($sak), 'dokument' => $this->dokument($arkiv, $post, $versjon)]);
        });
        // Kontrolloppgavene til arkivtjenesten: tellerne, dokumentene til journalføring, og handlingene.
        $r->legg('GET', '/api/arkiv/kontroll', fn (): Svar => Svar::json($this->kontroll()->tall($this->arkiv())));
        $r->legg('GET', '/api/arkiv/kontroll/journalposter/{gruppe}', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $postmottak = $this->postmottak();

            return Svar::json(['journalposter' => array_map(static fn (array $p): array => $postmottak->visJournalpost($p), $this->kontroll()->journalposter($arkiv, $v['gruppe']))]);
        });
        $r->legg('POST', '/api/arkiv/journalposter/{id}/journalfor', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $this->kontroll()->journalfor($arkiv, $v['id']);

            return Svar::json(['journalpost' => $this->skrevet($arkiv, $v['id'], 'urn:arkivar:hendelse:journalpost:journalfort')]);
        });
        $r->legg('POST', '/api/arkiv/saker/{id}/kontroller', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $this->kontroll()->kontroller($arkiv, $v['id']);

            return Svar::json(['sak' => $this->mappeEtter($arkiv, $v['id'], 'urn:arkivar:hendelse:mappe:kontrollert')]);
        });
        $r->legg('POST', '/api/arkiv/saker/{id}/avslutt', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $this->kontroll()->avslutt($arkiv, $v['id']);

            return Svar::json(['sak' => $this->mappeEtter($arkiv, $v['id'], 'urn:arkivar:hendelse:mappe:avsluttet')]);
        });
        // Dokumenter studenten skriver selv: opprett fra en mal, lagre mens det er under redigering, og ferdigstill.
        $r->legg('POST', '/api/arkiv/saker/{id}/dokumenter', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $id = $this->skriving()->opprett($arkiv, $v['id'], $f->json());

            return Svar::json(['journalpost' => $this->skrevet($arkiv, $id, 'urn:arkivar:hendelse:dokument:opprettet')], 201);
        });
        $r->legg('PUT', '/api/arkiv/journalposter/{id}/dokument', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $this->skriving()->lagre($arkiv, $v['id'], $f->json());

            return Svar::json(['journalpost' => $this->skrevet($arkiv, $v['id'], null)]);
        });
        $r->legg('POST', '/api/arkiv/journalposter/{id}/ferdigstill', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $this->skriving()->ferdigstill($arkiv, $v['id']);

            return Svar::json(['journalpost' => $this->skrevet($arkiv, $v['id'], 'urn:arkivar:hendelse:dokument:ferdigstilt')]);
        });
        $r->legg('DELETE', '/api/arkiv/journalposter/{id}', function (Foresporsel $f, array $v): Svar {
            $lopenummer = $this->postmottak()->slettJournalpost($this->arkiv(), $v['id']);
            $this->kjerne->logg()->skriv('urn:arkivar:hendelse:journalpost:slettet', $this->loggobjekt('journalpost', $v['id'], $lopenummer));

            return Svar::json(['ok' => true]);
        });
        $r->legg('GET', '/api/arkiv/journal', function (Foresporsel $f): Svar {
            $arkiv = $this->arkiv();
            $postmottak = $this->postmottak();
            $offentlig = ($f->query['offentlig'] ?? '') === '1';

            $poster = array_map(static fn (array $p): array => $postmottak->visJournalpost($p, $offentlig), $this->register()->journal($arkiv));

            return Svar::json(['offentlig' => $offentlig, 'journalposter' => $poster]);
        });
        $r->legg('POST', '/api/arkiv/nullstill', function (): Svar {
            $arkiv = $this->arkiv();
            $this->ovingsarkiv()->nullstill($arkiv);
            $objekt = new LoggObjekt(Roller::APP, 'arkiv', $this->kjerne->logg()->tekst('arkivar.objekttype.arkiv'), $arkiv, App::VIRKSOMHET);
            $this->kjerne->logg()->skriv('urn:arkivar:hendelse:arkiv:nullstilt', $objekt);

            return Svar::json(['ok' => true]);
        });
    }
}
