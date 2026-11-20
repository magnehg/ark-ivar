<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

/**
 * Kodelistene Ark-Ivar bruker, med kodene fra Noark 5. Navnene til visning står i språkfilene
 * (arkivar.jptype.<kode>, arkivar.jpstatus.<kode>, arkivar.saksstatus.<kode> og arkivar.handling.<kode>).
 */
final class Kodelister
{
    /** Journalposttype: inngående, utgående, organinternt for oppfølging, organinternt uten oppfølging, saksframlegg. */
    public const JOURNALPOSTTYPER = ['I', 'U', 'N', 'X', 'S'];

    /** Journalstatus: journalført, ferdigstilt fra saksbehandler, ekspedert, arkivert, midlertidig registrert. */
    public const JOURNALSTATUSER = ['J', 'F', 'E', 'A', 'M'];

    /** Saksstatus: under behandling, avsluttet. */
    public const SAKSSTATUSER = ['B', 'A'];

    /** Det arkivtjenesten kan gjøre med et dokument i postmottaket. */
    public const JOURNALFOR = 'journalfor';
    public const ARKIVER = 'arkiver';
    public const FAGSYSTEM = 'fagsystem';
    public const IKKE_ARKIVER = 'ikke_arkiver';
    public const HANDLINGER = [self::JOURNALFOR, self::ARKIVER, self::FAGSYSTEM, self::IKKE_ARKIVER];
}
