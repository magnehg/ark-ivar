<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Retting og flytting av journalposter som er ferdig registrert.
 *
 * Statusen avgjør hva som kan rettes. Før journalføringen (S, M, F, E) kan hele registreringen rettes: tittelen,
 * skjermingen, avsenderen eller mottakeren og dokumentdatoen. Når journalposten er journalført (J), kan bare
 * tittelen og skjermingen rettes. Et dokument som fortsatt er under redigering, endres på skriveflaten (Skriving),
 * og en arkivert journalpost og alt i en avsluttet mappe er låst.
 *
 * En journalpost kan flyttes til en annen åpen mappe. Den får neste ledige dokumentnummer der og beholder
 * løpenummeret. Det gjelder også dokumentene som fulgte med arkivet: dokumentteksten viser fortsatt til saken den
 * er skrevet i. Avskrivningen følger journalposten (koblingen er mellom journalpostene, ikke mappene), så et svar
 * og dokumentet det avskriver, kan etter en flytting ligge i hver sin mappe. Er journalposten registreringen av et
 * dokument fra postmottaket, vurderes dokumentene i begge mappene på nytt.
 */
final class Journalposter
{
    public const ALLE_FELT = 'alle';
    public const TITTEL_OG_SKJERMING = 'tittel';

    public function __construct(
        private readonly Db $db,
        private readonly ClockInterface $klokke,
        private readonly Grunndata $grunndata,
        private readonly Register $register,
        private readonly Postmottak $postmottak,
    ) {
    }

    /**
     * Hva som kan rettes på journalposten: alle registreringsfeltene, bare tittelen og skjermingen, eller ingenting
     * (null). $sakStatus er statusen på mappen når den er kjent.
     *
     * @param array<string, mixed> $post raden fra arkivar_journalpost
     */
    public static function kanRettes(array $post, ?string $sakStatus): ?string
    {
        $status = Felt::tekst($post['status']);
        if ($sakStatus === Kodelister::SAK_AVSLUTTET || $status === Kodelister::JP_ARKIVERT || Felt::valgfriTekst($post['dokumentstatus'] ?? null) === Kodelister::DOK_UNDER_REDIGERING) {
            return null;
        }

        return $status === Kodelister::JP_JOURNALFORT ? self::TITTEL_OG_SKJERMING : self::ALLE_FELT;
    }

    /**
     * Om journalposten kan flyttes: den er ferdig registrert, ikke arkivert, og mappen er åpen.
     *
     * @param array<string, mixed> $post raden fra arkivar_journalpost
     */
    public static function kanFlyttes(array $post, ?string $sakStatus): bool
    {
        return self::kanRettes($post, $sakStatus) !== null;
    }

    /**
     * Retter registreringen.
     *
     * @param array<string, mixed> $inn JSON-kroppen: tittel, offentlig_tittel, tilgangskode, skjerm_tittel og
     *                                  skjerm_part, og før journalføringen også dokumentdato og part_navn
     */
    public function rett(string $arkivId, string $journalpostId, array $inn): void
    {
        $idag = Dato::idag($this->klokke);
        $this->db->transaksjon(function () use ($arkivId, $journalpostId, $inn, $idag): void {
            $this->register->laas($arkivId);
            [$post, $sak] = $this->hent($arkivId, $journalpostId);
            $kan = self::kanRettes($post, Felt::tekst($sak['status'])) ?? throw new HttpFeil(409, 'kan_ikke_rettes', 'arkivar.feil.kan_ikke_rettes');
            $type = Felt::tekst($post['type']);
            if ($kan === self::TITTEL_OG_SKJERMING) {
                // Etter journalføringen står dokumentdatoen og avsenderen eller mottakeren fast.
                $inn = ['dokumentdato' => Felt::tekst($post['dokumentdato']), 'part_navn' => Felt::tekst($post['part_navn'])] + $inn;
                $part = Felt::tekst($post['part_navn']);
            } else {
                $part = Registreringsfelt::part($inn['part_navn'] ?? null, $type, true);
            }
            $this->register->oppdaterJournalpost($arkivId, $journalpostId, Registreringsfelt::les($inn, $this->grunndata, $idag) + ['type' => $type, 'part_navn' => $part]);
            if ($post['oppgave_id'] !== null) {
                // Registreringen av et dokument fra postmottaket er endret: vurderingen følger med.
                $this->postmottak->revurder($arkivId, Felt::tekst($sak['id']));
            }
        });
    }

    /**
     * Flytter journalposten til en annen mappe.
     *
     * @param array<string, mixed> $inn JSON-kroppen: sak (ID-en til mappen den skal til)
     */
    public function flytt(string $arkivId, string $journalpostId, array $inn): void
    {
        $tilId = Felt::tekst($inn['sak'] ?? null);
        $this->db->transaksjon(function () use ($arkivId, $journalpostId, $tilId): void {
            $this->register->laas($arkivId);
            [$post, $fra] = $this->hent($arkivId, $journalpostId);
            if (!self::kanFlyttes($post, Felt::tekst($fra['status']))) {
                throw new HttpFeil(409, 'kan_ikke_flyttes', 'arkivar.feil.kan_ikke_flyttes');
            }
            $til = $this->register->sak($arkivId, $tilId) ?? throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.flytt_mappe');
            if ($tilId === Felt::tekst($fra['id'])) {
                throw new HttpFeil(422, 'samme_mappe', 'arkivar.feil.flytt_samme_mappe');
            }
            if (Felt::tekst($til['status']) === Kodelister::SAK_AVSLUTTET) {
                throw new HttpFeil(409, 'mappe_avsluttet', 'arkivar.feil.mappe_avsluttet');
            }
            $this->register->flyttJournalpost($arkivId, $journalpostId, $tilId);
            if ($post['oppgave_id'] !== null) {
                // Om mappen er riktig for et dokument fra postmottaket, avhenger av hva som ligger i den: begge
                // mappene vurderes på nytt, den dokumentet forlot, og den det kom til.
                $this->postmottak->revurder($arkivId, Felt::tekst($fra['id']));
                $this->postmottak->revurder($arkivId, $tilId);
            }
        });
    }

    /**
     * Journalposten og mappen den ligger i.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function hent(string $arkivId, string $journalpostId): array
    {
        $post = $this->register->journalpost($arkivId, $journalpostId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        $sak = $this->register->sak($arkivId, Felt::tekst($post['sak_id'])) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');

        return [$post, $sak];
    }
}
