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
 * Kontrollen av en mappe setter den til B (under behandling). Avslutningen setter mappen til A og dokumentene til
 * A (arkivert), og kan bare gjøres når alle dokumentene i mappen er journalført.
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

    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke, private readonly Register $register)
    {
    }

    /** @return list<string> */
    public static function grupper(): array
    {
        return array_keys(self::GRUPPER);
    }

    /**
     * Antall kontrolloppgaver: dokumenter til journalføring per gruppe, og mapper til kontroll og til avslutning.
     *
     * @return array{journalforing: array<string, int>, saker: array{kontroll: int, avslutning: int}}
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

        return ['journalforing' => $journalforing, 'saker' => ['kontroll' => $saker[Kodelister::SAK_OPPRETTET], 'avslutning' => $saker[Kodelister::SAK_FERDIG]]];
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
            "SELECT j.*, s.saksaar, s.sekvens AS sakssekvens, s.tittel AS sakstittel, s.skjerm_tittel AS sak_skjermet, s.offentlig_tittel AS sak_offentlig_tittel,
                    s.arkivdel AS sak_arkivdel, s.klasse AS sak_klasse, s.skjerm_klasse AS sak_skjerm_klasse, s.tilgangskode AS sak_tilgangskode
               FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id
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
            $this->register->settJournalstatus($arkivId, $journalpostId, Kodelister::JP_JOURNALFORT, true, $idag);
        });
    }

    /** Arkivtjenesten har kontrollert en mappe saksbehandleren har opprettet: den er under behandling. */
    public function kontroller(string $arkivId, string $sakId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $sakId): void {
            $this->register->laas($arkivId);
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if (Felt::tekst($sak['status']) !== Kodelister::SAK_OPPRETTET) {
                throw new HttpFeil(409, 'ikke_til_kontroll', 'arkivar.feil.ikke_til_kontroll');
            }
            $this->register->settSaksstatus($arkivId, $sakId, Kodelister::SAK_UNDER_BEHANDLING);
        });
    }

    /**
     * Avslutter en mappe saksbehandleren har avsluttet. Alle dokumentene må være journalført først; de blir
     * arkivert sammen med mappen.
     */
    public function avslutt(string $arkivId, string $sakId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $sakId): void {
            $this->register->laas($arkivId);
            $sak = $this->register->sak($arkivId, $sakId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
            if (Felt::tekst($sak['status']) !== Kodelister::SAK_FERDIG) {
                throw new HttpFeil(409, 'ikke_til_avslutning', 'arkivar.feil.ikke_til_avslutning');
            }
            foreach ($this->register->journalposter($arkivId, $sakId) as $post) {
                if (!in_array(Felt::tekst($post['status']), [Kodelister::JP_JOURNALFORT, Kodelister::JP_ARKIVERT], true)) {
                    throw new HttpFeil(409, 'mappe_har_restanser', 'arkivar.feil.mappe_har_restanser');
                }
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
