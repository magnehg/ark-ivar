<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Kontrolloppgavene til arkivtjenesten: det saksbehandlerne har gjort ferdig, og som arkivtjenesten skal
 * kontrollere. Dokumenter til journalføring er utgående som er ekspedert (E), interne som er ferdigstilt eller
 * ekspedert (F, E), og inngående som saksbehandleren har registrert selv (S) eller som er midlertidig registrert
 * (M). Mapper til kontroll er dem saksbehandleren har opprettet (R), og mapper til avslutning er dem
 * saksbehandleren har avsluttet (S). Et dokument studenten fortsatt skriver på (dokumentstatus B), er ikke en
 * kontrolloppgave ennå.
 *
 * Journalføringen setter journalposten til J med dagens dato som journaldato, og dokumentet får arkivformat.
 * Løpenummeret er tildelt da journalposten ble opprettet, og endres ikke. Journalføringen kan angres samme dag.
 * Kontrollen av en mappe setter den til B (under behandling). Fra B sendes mappen til avslutning (S), og fra S kan
 * den sendes tilbake til behandling. Avslutningen setter mappen til A og dokumentene til A (arkivert), og kan bare
 * gjøres når sjekklisten er tom: alle dokumentene er journalført, ingen inngående dokumenter står uten avskrivning
 * (restanser), og ingen dokumenter er under redigering.
 *
 * Restansene (journalførte inngående dokumenter uten avskrivning) er også en liste under kontrolloppgavene.
 *
 * @phpstan-type Mangel array{slag: string, journalpost: array<string, mixed>}
 */
final class Kontroll
{
    public const INNGAENDE = 'inngaende';
    public const INTERNE = 'interne';
    public const UTGAENDE = 'utgaende';

    /** Journalposttypene og journalstatusene i hver gruppe av dokumenter til journalføring. */
    private const GRUPPER = [
        self::INNGAENDE => [['I'], [Kodelister::JP_REGISTRERT, Kodelister::JP_MIDLERTIDIG]],
        self::INTERNE => [['N', 'X', 'S'], [Kodelister::JP_FERDIG, Kodelister::JP_EKSPEDERT]],
        self::UTGAENDE => [['U'], [Kodelister::JP_EKSPEDERT]],
    ];

    /** Det som kan mangle før en mappe kan avsluttes. */
    public const IKKE_JOURNALFORT = 'ikke_journalfort';
    public const RESTANSE = 'restanse';
    public const UNDER_REDIGERING = 'under_redigering';

    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke, private readonly Register $register, private readonly Grunndata $grunndata)
    {
    }

    /** @return list<string> */
    public static function grupper(): array
    {
        return array_keys(self::GRUPPER);
    }

    /**
     * Antall kontrolloppgaver: dokumenter til journalføring per gruppe, mapper til kontroll og til avslutning, og
     * restansene.
     *
     * @return array{journalforing: array<string, int>, saker: array{kontroll: int, avslutning: int}, restanser: int}
     */
    public function tall(string $arkivId): array
    {
        $journalforing = [];
        foreach (self::grupper() as $gruppe) {
            [$vilkar, $verdier] = self::vilkar($gruppe);
            $journalforing[$gruppe] = Felt::tall($this->db->verdi("SELECT COUNT(*) FROM arkivar_journalpost j WHERE j.arkiv_id = ? AND $vilkar", [$arkivId, ...$verdier]));
        }
        $saker = [Kodelister::SAK_OPPRETTET => 0, Kodelister::SAK_FERDIG => 0];
        foreach ($this->db->alle('SELECT status, COUNT(*) AS antall FROM arkivar_sak WHERE arkiv_id = ? AND status IN (?, ?) GROUP BY status', [$arkivId, ...array_keys($saker)]) as $rad) {
            $saker[Felt::tekst($rad['status'])] = Felt::tall($rad['antall']);
        }

        return [
            'journalforing' => $journalforing,
            'saker' => ['kontroll' => $saker[Kodelister::SAK_OPPRETTET], 'avslutning' => $saker[Kodelister::SAK_FERDIG]],
            'restanser' => $this->register->antallRestanser($arkivId),
        ];
    }

    /**
     * Dokumentene til journalføring i en gruppe, eldst først, med mappen de ligger i (som journalen).
     *
     * @return list<array<string, mixed>>
     */
    public function journalposter(string $arkivId, string $gruppe): array
    {
        [$vilkar, $verdier] = self::vilkar($gruppe);

        return $this->db->alle(
            'SELECT ' . Register::MED_SAK . " FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id
              WHERE j.arkiv_id = ? AND $vilkar ORDER BY j.dokumentdato, j.sekvens",
            [$arkivId, ...$verdier],
        );
    }

    /** Journalfører et dokument som står til journalføring. */
    public function journalfor(string $arkivId, string $journalpostId): void
    {
        $idag = Dato::idag($this->klokke);
        $this->db->transaksjon(function () use ($arkivId, $journalpostId, $idag): void {
            $this->register->laas($arkivId);
            $post = $this->register->journalpost($arkivId, $journalpostId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if (self::gruppe($post) === null) {
                throw new HttpFeil(409, 'ikke_til_journalforing', 'arkivar.feil.ikke_til_journalforing');
            }
            $this->krevApenMappe($arkivId, $post);
            $this->register->settJournalstatus($arkivId, $journalpostId, Kodelister::JP_JOURNALFORT, true, $idag);
        });
    }

    /**
     * Statusen journalposten hadde før arkivtjenesten journalførte den, og journaldatoen den hadde da. Null når
     * journalføringen ikke kan angres: bare samme dag, og bare for et dokument som sto til journalføring (ikke et
     * dokument fra postmottaket eller en registrering uten dokument, som ble journalført da de ble registrert).
     *
     * @param array<string, mixed> $post raden fra arkivar_journalpost
     * @return array{status: string, journaldato: string}|null
     */
    public function forJournalforing(array $post): ?array
    {
        $idag = Dato::idag($this->klokke);
        if (Felt::tekst($post['status']) !== Kodelister::JP_JOURNALFORT || Felt::tekst($post['journaldato']) !== $idag || $post['oppgave_id'] !== null) {
            return null;
        }
        $startdokument = Felt::valgfriTekst($post['startdokument']);
        if ($startdokument !== null) {
            // Et dokument som fulgte med arkivet: statusen og journaldatoen står i grunndataene.
            $start = $this->grunndata->startpost($startdokument);

            return $start !== null && self::gruppe($start) !== null ? ['status' => $start['status'], 'journaldato' => $start['journaldato']] : null;
        }
        if ($this->db->verdi('SELECT 1 FROM arkivar_dokument WHERE journalpost_id = ? LIMIT 1', [Felt::tekst($post['id'])]) === null) {
            return null;
        }
        // Et dokument studenten har skrevet: statusen saksbehandleren satte da dokumentet ble ferdigstilt, og
        // journaldatoen fra før journalføringen, som er dagen journalposten ble opprettet.
        $status = match (Felt::tekst($post['type'])) {
            'U' => Kodelister::JP_EKSPEDERT,
            'I' => Kodelister::JP_MIDLERTIDIG,
            default => Kodelister::JP_FERDIG,
        };

        return ['status' => $status, 'journaldato' => Dato::norsk(Felt::tekst($post['opprettet']))];
    }

    /** Angrer journalføringen: journalposten står igjen til journalføring og er ute av journalen. */
    public function angreJournalforing(string $arkivId, string $journalpostId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $journalpostId): void {
            $this->register->laas($arkivId);
            $post = $this->register->journalpost($arkivId, $journalpostId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            $for = $this->forJournalforing($post) ?? throw new HttpFeil(409, 'kan_ikke_angres', 'arkivar.feil.journalforing_kan_ikke_angres');
            $this->krevApenMappe($arkivId, $post);
            if ($this->register->avskriverArkivert($arkivId, $journalpostId)) {
                // Svaret kan ligge i en annen mappe enn dokumentet det avskriver. Er det dokumentet arkivert, står
                // avskrivningen fast, og svaret som avskriver det, kan ikke tas ut av journalen.
                throw new HttpFeil(409, 'avskriver_arkivert', 'arkivar.feil.avskriver_arkivert');
            }
            $this->register->settJournalstatus($arkivId, $journalpostId, $for['status'], false, $for['journaldato']);
        });
    }

    /**
     * Ingenting endres i en avsluttet mappe.
     *
     * @param array<string, mixed> $post raden fra arkivar_journalpost
     */
    private function krevApenMappe(string $arkivId, array $post): void
    {
        $sak = $this->register->sak($arkivId, Felt::tekst($post['sak_id']));
        if ($sak === null || Felt::tekst($sak['status']) === Kodelister::SAK_AVSLUTTET) {
            throw new HttpFeil(409, 'mappe_avsluttet', 'arkivar.feil.mappe_laast');
        }
    }

    /** Setter mappen fra en status til en annen. Står den ikke i $fra, avvises det med feilkoden og teksten. */
    private function settMappestatus(string $arkivId, string $sakId, string $fra, string $til, string $feil, string $tekst): void
    {
        $this->db->transaksjon(function () use ($arkivId, $sakId, $fra, $til, $feil, $tekst): void {
            $this->register->laas($arkivId);
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if (Felt::tekst($sak['status']) !== $fra) {
                throw new HttpFeil(409, $feil, $tekst);
            }
            $this->register->settSaksstatus($arkivId, $sakId, $til);
        });
    }

    /** Saksbehandlingen er ferdig: mappen sendes til avslutning (S), der arkivtjenesten kontrollerer og avslutter den. */
    public function sendTilAvslutning(string $arkivId, string $sakId): void
    {
        $this->settMappestatus($arkivId, $sakId, Kodelister::SAK_UNDER_BEHANDLING, Kodelister::SAK_FERDIG, 'ikke_under_behandling', 'arkivar.feil.ikke_under_behandling');
    }

    /** Mappen var ikke ferdig likevel: den sendes tilbake til behandling (B). */
    public function sendTilbake(string $arkivId, string $sakId): void
    {
        $this->settMappestatus($arkivId, $sakId, Kodelister::SAK_FERDIG, Kodelister::SAK_UNDER_BEHANDLING, 'ikke_til_avslutning', 'arkivar.feil.ikke_til_avslutning');
    }

    /**
     * Sjekklisten før en mappe avsluttes: dokumentene som ikke er journalført, restansene (inngående uten
     * avskrivning) og dokumentene som fortsatt er under redigering, i den rekkefølgen dokumentene står i mappen.
     * Mappen kan avsluttes når listen er tom.
     *
     * @return array{mangler: list<Mangel>, dokumenter: int}
     */
    public function sjekkliste(string $arkivId, string $sakId): array
    {
        $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        $poster = $this->register->journalposter($arkivId, $sakId);
        $mangler = [];
        foreach ($poster as $post) {
            $post['sak_status'] = $sak['status'];
            if (Felt::valgfriTekst($post['dokumentstatus']) === Kodelister::DOK_UNDER_REDIGERING) {
                $mangler[] = ['slag' => self::UNDER_REDIGERING, 'journalpost' => $post];
            } elseif (!in_array(Felt::tekst($post['status']), [Kodelister::JP_JOURNALFORT, Kodelister::JP_ARKIVERT], true)) {
                $mangler[] = ['slag' => self::IKKE_JOURNALFORT, 'journalpost' => $post];
            } elseif (Register::kanVaereRestanse($post) && $this->register->avskrivning($arkivId, Felt::tekst($post['id'])) === null) {
                $mangler[] = ['slag' => self::RESTANSE, 'journalpost' => $post];
            }
        }

        return ['mangler' => $mangler, 'dokumenter' => count($poster)];
    }

    /** Arkivtjenesten har kontrollert en mappe saksbehandleren har opprettet: den er under behandling. */
    public function kontroller(string $arkivId, string $sakId): void
    {
        $this->settMappestatus($arkivId, $sakId, Kodelister::SAK_OPPRETTET, Kodelister::SAK_UNDER_BEHANDLING, 'ikke_til_kontroll', 'arkivar.feil.ikke_til_kontroll');
    }

    /**
     * Avslutter en mappe saksbehandleren har avsluttet. Sjekklisten må være tom først (alt journalført, ingen
     * restanser og ingenting under redigering); dokumentene blir arkivert sammen med mappen.
     */
    public function avslutt(string $arkivId, string $sakId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $sakId): void {
            $this->register->laas($arkivId);
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if (Felt::tekst($sak['status']) !== Kodelister::SAK_FERDIG) {
                throw new HttpFeil(409, 'ikke_til_avslutning', 'arkivar.feil.ikke_til_avslutning');
            }
            if ($this->sjekkliste($arkivId, $sakId)['mangler'] !== []) {
                throw new HttpFeil(409, 'mappe_har_restanser', 'arkivar.feil.mappe_har_restanser');
            }
            $this->register->arkiverJournalposter($arkivId, $sakId);
            $this->register->settSaksstatus($arkivId, $sakId, Kodelister::SAK_AVSLUTTET);
        });
    }

    /**
     * Gruppen journalposten står til journalføring i, eller null når den ikke er en kontrolloppgave.
     *
     * @param array<string, mixed> $post raden fra arkivar_journalpost
     */
    public static function gruppe(array $post): ?string
    {
        if (Felt::valgfriTekst($post['dokumentstatus'] ?? null) === Kodelister::DOK_UNDER_REDIGERING) {
            return null;
        }
        foreach (self::GRUPPER as $gruppe => [$typer, $statuser]) {
            if (in_array(Felt::tekst($post['type']), $typer, true) && in_array(Felt::tekst($post['status']), $statuser, true)) {
                return $gruppe;
            }
        }

        return null;
    }

    /**
     * Vilkåret i SQL for en gruppe, og verdiene til det.
     *
     * @return array{string, list<string>}
     */
    private static function vilkar(string $gruppe): array
    {
        [$typer, $statuser] = self::GRUPPER[$gruppe] ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        $sporsmal = static fn (array $verdier): string => implode(', ', array_fill(0, count($verdier), '?'));

        return [
            'j.type IN (' . $sporsmal($typer) . ') AND j.status IN (' . $sporsmal($statuser) . ') AND (j.dokumentstatus IS NULL OR j.dokumentstatus <> ?)',
            [...$typer, ...$statuser, Kodelister::DOK_UNDER_REDIGERING],
        ];
    }
}
