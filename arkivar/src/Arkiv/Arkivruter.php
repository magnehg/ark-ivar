<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use ArkIvar\App;
use ArkIvar\Brukere\Roller;
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

    private function postmottak(): Postmottak
    {
        return new Postmottak($this->kjerne->db(), $this->klokke, $this->grunndata(), $this->oppgaver ??= new Oppgavesett(), $this->register());
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

            return Svar::json([
                'arkivdeler' => $g->arkivdeler(), 'klasser' => $g->klasser(), 'enheter' => $g->enheter(), 'ansatte' => $g->ansatte(), 'tilgangskoder' => $g->tilgangskoder(),
                'journalposttyper' => Kodelister::JOURNALPOSTTYPER, 'handlinger' => Kodelister::HANDLINGER,
            ]);
        });
        $r->legg('GET', '/api/arkiv/postmottak', fn (): Svar => Svar::json(['dokumenter' => $this->postmottak()->liste($this->arkiv())]));
        $r->legg('GET', '/api/arkiv/postmottak/{id}', fn (Foresporsel $f, array $v): Svar => Svar::json($this->postmottak()->vis($this->arkiv(), $v['id'])));
        $r->legg('POST', '/api/arkiv/postmottak/{id}', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $svar = $this->postmottak()->registrer($arkiv, $v['id'], $f->json());
            $this->kjerne->logg()->skriv(
                'urn:arkivar:hendelse:postmottak:behandlet',
                new LoggObjekt(Roller::APP, 'dokument', $this->kjerne->logg()->tekst('arkivar.objekttype.dokument'), $v['id'], $v['id']),
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
        $r->legg('GET', '/api/arkiv/saker/{id}', function (Foresporsel $f, array $v): Svar {
            $arkiv = $this->arkiv();
            $register = $this->register();
            $sak = $register->sak($arkiv, $v['id']) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');

            return Svar::json([
                'sak' => $register->visSak($sak),
                'journalposter' => array_map(static fn (array $p): array => $register->visJournalpost($p), $register->journalposter($arkiv, $v['id'])),
            ]);
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
