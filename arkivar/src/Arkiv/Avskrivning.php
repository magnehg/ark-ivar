<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;
use Psr\Clock\ClockInterface;

/**
 * Avskrivning av inngående dokumenter uten svar. Et inngående dokument skal avskrives: enten besvares det (da
 * avskrives det med BU når svaret er ekspedert, se Skriving), eller så tar arkivtjenesten stilling til at det ikke
 * skal ha skriftlig svar og velger en avskrivningsmåte (tatt til etterretning, tatt til orientering, besvart på
 * telefon, med e-post eller med notat). BU og SA kan ikke velges her: BU settes av svaret, og SA hører til
 * avslutningen av saksmappen. En merknad kan følge med og lagres på journalposten.
 *
 * En avskrivning uten svar kan angres så lenge mappen er åpen. Da er dokumentet igjen en restanse. BU kan ikke
 * angres for seg: den følger svaret, og et ekspedert svar uten avskrivningen ville stått som et svar som ikke
 * avskriver. SA følger avslutningen av mappen.
 */
final class Avskrivning
{
    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke, private readonly Register $register, private readonly Merknader $merknader)
    {
    }

    /**
     * Om dokumentet kan avskrives eller besvares: et inngående dokument som er ferdig registrert og ikke arkivert.
     * (Om det alt er avskrevet, og om mappen er åpen, sjekkes for seg.)
     *
     * @param array<string, mixed> $post raden fra arkivar_journalpost
     */
    public static function gjelder(array $post): bool
    {
        return Felt::tekst($post['type']) === 'I'
            && Felt::valgfriTekst($post['dokumentstatus'] ?? null) !== Kodelister::DOK_UNDER_REDIGERING
            && Felt::tekst($post['status']) !== Kodelister::JP_ARKIVERT;
    }

    /**
     * Avskriver et inngående dokument uten svar.
     *
     * @param array<string, mixed> $inn JSON-kroppen: mate (avskrivningsmåten) og merknad
     * @param ?string $brukerId den som avskriver (står på merknaden)
     */
    public function avskriv(string $arkivId, string $journalpostId, array $inn, ?string $brukerId): void
    {
        $mate = Felt::tekst($inn['mate'] ?? null);
        if (!in_array($mate, Kodelister::AVSKRIVNING_UTEN_SVAR, true)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.avskrivningsmate');
        }
        $merknad = Merknader::les($inn['merknad'] ?? null);
        $idag = Dato::idag($this->klokke);
        $this->db->transaksjon(function () use ($arkivId, $journalpostId, $mate, $merknad, $idag, $brukerId): void {
            $this->register->laas($arkivId);
            $post = $this->apen($arkivId, $journalpostId);
            if (!self::gjelder($post)) {
                throw new HttpFeil(409, 'kan_ikke_avskrives', 'arkivar.feil.kan_ikke_avskrives');
            }
            if ($this->register->avskrivning($arkivId, $journalpostId) !== null) {
                throw new HttpFeil(409, 'alt_avskrevet', 'arkivar.feil.alt_avskrevet');
            }
            $this->register->avskriv($arkivId, $journalpostId, $mate, $idag);
            if ($merknad !== '') {
                $this->merknader->leggPaaJournalpost($arkivId, $journalpostId, $merknad, $brukerId);
            }
        });
    }

    /** Om en avskrivning med denne måten kan angres: bare dem arkivtjenesten selv har valgt (ikke BU og SA). */
    public static function kanAngres(mixed $mate): bool
    {
        return in_array($mate, Kodelister::AVSKRIVNING_UTEN_SVAR, true);
    }

    /** Angrer avskrivningen: dokumentet står igjen uten avskrivning. Merknadene blir stående. */
    public function angre(string $arkivId, string $journalpostId): void
    {
        $this->db->transaksjon(function () use ($arkivId, $journalpostId): void {
            $this->register->laas($arkivId);
            $post = $this->apen($arkivId, $journalpostId);
            $avskrivning = $this->register->avskrivning($arkivId, $journalpostId) ?? throw new HttpFeil(409, 'ikke_avskrevet', 'arkivar.feil.ikke_avskrevet');
            if (Felt::tekst($post['status']) === Kodelister::JP_ARKIVERT) {
                throw new HttpFeil(409, 'mappe_avsluttet', 'arkivar.feil.mappe_laast');
            }
            if (!self::kanAngres($avskrivning['mate'])) {
                throw new HttpFeil(409, 'avskrivning_kan_ikke_angres', 'arkivar.feil.avskrivning_kan_ikke_angres');
            }
            $this->register->fjernAvskrivning($arkivId, $journalpostId);
        });
    }

    /**
     * Journalposten, når mappen den ligger i, er åpen. Ingenting endres i en avsluttet mappe.
     *
     * @return array<string, mixed>
     */
    private function apen(string $arkivId, string $journalpostId): array
    {
        $post = $this->register->journalpost($arkivId, $journalpostId) ?? throw new HttpFeil(404, 'ikke_funnet', 'bivrost.feil.http.ikke_funnet');
        $sak = $this->register->sak($arkivId, Felt::tekst($post['sak_id']));
        if ($sak === null || Felt::tekst($sak['status']) === Kodelister::SAK_AVSLUTTET) {
            throw new HttpFeil(409, 'mappe_avsluttet', 'arkivar.feil.mappe_laast');
        }

        return $post;
    }
}
