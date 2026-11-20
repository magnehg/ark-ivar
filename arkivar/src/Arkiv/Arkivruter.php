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
        return new Ovingsarkiv($this->kjerne->db(), $this->klokke, $this->grunndata(), $this->register());
    }

    private function oppgaver(): Oppgavesett
    {
        return $this->oppgaver ??= new Oppgavesett();
    }

    private function mapper(): Mapper
    {
        return new Mapper($this->klokke, $this->grunndata(), $this->register());
    }

    /** Loggobjektet for en mappe eller journalpost: typen og nummeret, ikke tittelen. */
    private function loggobjekt(string $type, string $id, string $nummer): LoggObjekt
    {
        return new LoggObjekt(Roller::APP, $type, $this->kjerne->logg()->tekst('arkivar.objekttype.' . $type), $id, $nummer);
    }

    private function postmottak(): Postmottak
    {
        return new Postmottak($this->kjerne->db(), $this->klokke, $this->grunndata(), $this->oppgaver(), $this->register());
    }

    /**
     * Dokumentet til en journalpost: fra postmottaket når studenten har registrert det, ellers dokumentet som fulgte
     * med arkivet. Null når posten ikke har noe dokument.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    private function dokument(string $arkivId, array $post): ?array
    {
        $saksnumre = $this->register()->saksnumre($arkivId);
        if (is_string($post['oppgave_id'])) {
            $oppgave = $this->oppgaver()->hent($post['oppgave_id']);

            return $oppgave !== null ? Grunndata::medSaksnumre(Oppgavesett::dokument($oppgave), $saksnumre) : null;
        }
        if (!is_string($post['startdokument'])) {
            return null;
        }
        $dokument = $this->grunndata()->startdokument($post['startdokument']);

        return $dokument !== null ? Grunndata::medSaksnumre($dokument, $saksnumre, explode('#', $post['startdokument'])[0]) : null;
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
                'ansatte' => $g->ansatte(), 'tilgangskoder' => $g->tilgangskoder(), 'journalposttyper' => Kodelister::JOURNALPOSTTYPER, 'handlinger' => Kodelister::HANDLINGER,
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
            $sok = Felt::tekst($f->query['q'] ?? null);

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
            $sak = $register->sak($arkiv, $v['id']) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $poster = $register->journalposter($arkiv, $v['id']);

            return Svar::json([
                'sak' => $register->visSak($sak),
                'journalposter' => array_map(static fn (array $p): array => $register->visJournalpost($p), $poster),
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

            return Svar::json(['journalpost' => $register->visJournalpost($post), 'sak' => $register->visSak($sak), 'dokument' => $this->dokument($arkiv, $post)]);
        });
        $r->legg('DELETE', '/api/arkiv/journalposter/{id}', function (Foresporsel $f, array $v): Svar {
            $this->postmottak()->slettJournalpost($this->arkiv(), $v['id']);
            $this->kjerne->logg()->skriv('urn:arkivar:hendelse:journalpost:slettet', $this->loggobjekt('journalpost', $v['id'], $v['id']));

            return Svar::json(['ok' => true]);
        });
        $r->legg('GET', '/api/arkiv/journal', function (Foresporsel $f): Svar {
            $arkiv = $this->arkiv();
            $register = $this->register();
            $offentlig = ($f->query['offentlig'] ?? '') === '1';

            return Svar::json(['offentlig' => $offentlig, 'journalposter' => array_map(static fn (array $p): array => $register->visJournalpost($p, $offentlig), $register->journal($arkiv))]);
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
