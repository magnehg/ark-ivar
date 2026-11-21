<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

/**
 * Kodelistene Ark-Ivar bruker, med kodene fra Noark 5. Navnene til visning og forklaringen av når hver kode brukes,
 * står i språkfilene (arkivar.jptype.<kode>, arkivar.saksstatus.<kode>, arkivar.jpstatus.<kode>,
 * arkivar.dokstatus.<kode>, arkivar.variantformat.<kode>, arkivar.avskrivningsmate.<kode> og arkivar.handling.<kode>;
 * forklaringen har «.bruk» etter koden). Statuskodene er hele kodelisten fra standarden, også kodene Ark-Ivar ikke setter selv: studentene skal
 * kjenne dem igjen fra et sak- og arkivsystem. Siden Arkivstruktur viser listene.
 */
final class Kodelister
{
    /** Journalposttype: inngående, utgående, organinternt for oppfølging, organinternt uten oppfølging, saksframlegg. */
    public const JOURNALPOSTTYPER = ['I', 'U', 'N', 'X', 'S'];

    /**
     * Saksstatus (på mappen): under behandling, avsluttet, utgår, opprettet av saksbehandler, avsluttet av
     * saksbehandler, unntatt prosesstyring, ferdig fra saksbehandler.
     */
    public const SAK_UNDER_BEHANDLING = 'B';
    public const SAK_AVSLUTTET = 'A';
    /** Opprettet av saksbehandleren: arkivtjenesten har ikke kontrollert mappen ennå. */
    public const SAK_OPPRETTET = 'R';
    /** Avsluttet av saksbehandleren: arkivtjenesten skal avslutte mappen. */
    public const SAK_FERDIG = 'S';
    public const SAKSSTATUSER = [self::SAK_UNDER_BEHANDLING, self::SAK_AVSLUTTET, 'U', self::SAK_OPPRETTET, self::SAK_FERDIG, 'P', 'F'];

    /**
     * Journalstatus (på journalposten): journalført, ferdigstilt fra saksbehandler, godkjent av leder, ekspedert,
     * arkivert, utgår, midlertidig registrering av innkommet dokument, saksbehandler har registrert innkommet
     * dokument, reservert dokument.
     */
    public const JP_JOURNALFORT = 'J';
    public const JP_ARKIVERT = 'A';
    /** Reservert dokument: saksbehandleren har opprettet journalposten og skriver på dokumentet. */
    public const JP_RESERVERT = 'R';
    /** Midlertidig registrering av innkommet dokument: brevet er registrert, men ikke kontrollert og journalført. */
    public const JP_MIDLERTIDIG = 'M';
    /** Ferdigstilt fra saksbehandleren: dokumentet er ferdig, og journalposten venter på journalføring. */
    public const JP_FERDIG = 'F';
    /** Saksbehandleren har registrert et innkommet dokument selv. */
    public const JP_REGISTRERT = 'S';
    public const JOURNALSTATUSER = [self::JP_JOURNALFORT, self::JP_FERDIG, 'G', self::JP_EKSPEDERT, self::JP_ARKIVERT, 'U', self::JP_MIDLERTIDIG, self::JP_REGISTRERT, self::JP_RESERVERT];

    /** Dokumentstatus (på dokumentfilen): under redigering, ferdigstilt. */
    public const DOK_UNDER_REDIGERING = 'B';
    public const DOK_FERDIGSTILT = 'F';
    public const DOKUMENTSTATUSER = [self::DOK_UNDER_REDIGERING, self::DOK_FERDIGSTILT];

    /** Variantformat (på dokumentfilen): produksjonsformat, arkivformat, dokument der deler av innholdet er skjermet. */
    public const VARIANT_PRODUKSJON = 'P';
    public const VARIANT_ARKIV = 'A';
    public const VARIANTFORMATER = [self::VARIANT_PRODUKSJON, self::VARIANT_ARKIV, 'O'];

    public const JP_EKSPEDERT = 'E';

    /** Journalstatusene der dokumentet er ferdig behandlet av arkivtjenesten og gjort om til arkivformat. */
    public const MED_ARKIVFORMAT = [self::JP_JOURNALFORT, self::JP_EKSPEDERT, self::JP_ARKIVERT];

    /**
     * Variantformatet til dokumentfilen i en journalpost med denne statusen: når journalposten er journalført,
     * ekspedert eller arkivert, er dokumentet gjort om til arkivformat. Før det finnes det bare i produksjonsformat.
     */
    public static function variantformat(string $journalstatus): string
    {
        return in_array($journalstatus, self::MED_ARKIVFORMAT, true) ? self::VARIANT_ARKIV : self::VARIANT_PRODUKSJON;
    }

    /**
     * Avskrivningsmåte (på et inngående dokument), fra kodelisten i Noark 5 tjenestegrensesnitt: besvart med brev,
     * besvart med e-post, besvart på telefon, tatt til etterretning, tatt til orientering, besvart med notat og
     * saken ble avsluttet.
     */
    public const AVSKR_BREV = 'BU';
    public const AVSKR_EPOST = 'BE';
    public const AVSKR_TELEFON = 'TLF';
    public const AVSKR_ETTERRETNING = 'TE';
    public const AVSKR_ORIENTERING = 'TO';
    public const AVSKR_NOTAT = 'BN';
    public const AVSKR_SAK_AVSLUTTET = 'SA';
    public const AVSKRIVNINGSMATER = [
        self::AVSKR_BREV, self::AVSKR_EPOST, self::AVSKR_TELEFON, self::AVSKR_ETTERRETNING, self::AVSKR_ORIENTERING, self::AVSKR_NOTAT, self::AVSKR_SAK_AVSLUTTET,
    ];

    /**
     * Avskrivningsmåtene arkivtjenesten kan velge selv når et dokument avskrives uten svar. BU settes når svaret er
     * ekspedert, og SA når saksmappen avsluttes.
     */
    public const AVSKRIVNING_UTEN_SVAR = [self::AVSKR_ETTERRETNING, self::AVSKR_ORIENTERING, self::AVSKR_TELEFON, self::AVSKR_EPOST, self::AVSKR_NOTAT];

    /** Det arkivtjenesten kan gjøre med et dokument i postmottaket. */
    public const JOURNALFOR = 'journalfor';
    public const ARKIVER = 'arkiver';
    public const FAGSYSTEM = 'fagsystem';
    public const IKKE_ARKIVER = 'ikke_arkiver';
    public const HANDLINGER = [self::JOURNALFOR, self::ARKIVER, self::FAGSYSTEM, self::IKKE_ARKIVER];

    /** Behandlingene der dokumentet registreres i en mappe. */
    public const I_ARKIVET = [self::JOURNALFOR, self::ARKIVER];
}
