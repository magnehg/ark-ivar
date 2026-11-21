<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\HttpFeil;

/**
 * Feltene alle registreringer av en journalpost har felles, lest og kontrollert fra skjemaet: tittelen,
 * dokumentdatoen, tilgangskoden og skjermingen. Brukes både når et dokument fra postmottaket registreres, og når
 * studenten skriver et dokument selv, så reglene er de samme begge steder.
 *
 * @phpstan-type Fellesfelt array{tittel: string, offentlig_tittel: ?string, dokumentdato: string, tilgangskode: ?string, skjerm_tittel: bool, skjerm_part: bool}
 */
final class Registreringsfelt
{
    /**
     * @param array<string, mixed> $inn JSON-kroppen: tittel, offentlig_tittel, dokumentdato, tilgangskode, skjerm_tittel og skjerm_part
     * @param string $idag dagens dato i norsk tid: dokumentdatoen kan ikke ligge frem i tid
     * @return Fellesfelt
     */
    public static function les(array $inn, Grunndata $grunndata, string $idag): array
    {
        $tittel = trim(Felt::tekst($inn['tittel'] ?? null));
        $lengde = mb_strlen($tittel, 'UTF-8');
        if ($lengde < 3 || $lengde > 500) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.tittel');
        }
        $dokumentdato = Felt::tekst($inn['dokumentdato'] ?? null);
        $dato = \DateTimeImmutable::createFromFormat('!Y-m-d', $dokumentdato);
        if ($dato === false || $dato->format('Y-m-d') !== $dokumentdato || $dokumentdato > $idag) {
            throw new HttpFeil(422, 'ugyldig_dato', 'arkivar.feil.dokumentdato');
        }
        $tilgangskode = trim(Felt::tekst($inn['tilgangskode'] ?? null));
        if ($tilgangskode !== '' && !$grunndata->harTilgangskode($tilgangskode)) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.tilgangskode');
        }
        $skjermTittel = ($inn['skjerm_tittel'] ?? false) === true;
        $skjermPart = ($inn['skjerm_part'] ?? false) === true;
        if (($skjermTittel || $skjermPart) && $tilgangskode === '') {
            // Skjerming i journalen må ha en hjemmel, og hjemmelen følger tilgangskoden.
            throw new HttpFeil(422, 'skjerming_uten_hjemmel', 'arkivar.feil.skjerming_uten_hjemmel');
        }
        $offentlig = trim(Felt::tekst($inn['offentlig_tittel'] ?? null));
        if (mb_strlen($offentlig, 'UTF-8') > 500) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.offentlig_tittel');
        }

        return [
            'tittel' => $tittel,
            'offentlig_tittel' => $skjermTittel ? ($offentlig !== '' ? $offentlig : '*****') : null,
            'dokumentdato' => $dokumentdato,
            'tilgangskode' => $tilgangskode !== '' ? $tilgangskode : null,
            'skjerm_tittel' => $skjermTittel,
            'skjerm_part' => $skjermPart,
        ];
    }

    /** Avsenderen eller mottakeren. Den kreves for inngående og utgående dokumenter når $kreves er sann. */
    public static function part(mixed $verdi, string $type, bool $kreves): string
    {
        $part = trim(Felt::tekst($verdi));
        if (mb_strlen($part, 'UTF-8') > 300 || ($kreves && in_array($type, ['I', 'U'], true) && $part === '')) {
            throw new HttpFeil(422, 'ugyldig_felt', 'arkivar.feil.part');
        }

        return $part;
    }
}
