// Typene for øvingsarkivet (API-et under /api/arkiv/) og arkivstrukturen, som er lik for alle og hentes én gang.
import { useEffect, useState } from 'react';
import { api } from './api';

export type Handling = 'journalfor' | 'arkiver' | 'fagsystem' | 'ikke_arkiver';
export type Postmottaksrad = { id: string; kanal: 'brev' | 'epost'; mottatt: string; emne: string; avsender: string; behandlet: boolean; handling: Handling | null; poeng: number | null; maks: number | null };
export type Dokument = {
  id: string; kanal: 'brev' | 'epost'; mottatt: string; dokumentdato: string; emne: string; tekst: string[]; vedlegg: string[];
  avsender: { navn: string; adresse: string | null; epost: string | null };
};
export type Kriterium = { id: string; ok: boolean; ditt: string; riktig: string; forklaring: string };
export type Sak = {
  id: string; saksnummer: string; tittel: string; skjerm_tittel: boolean; offentlig_tittel: string | null; arkivdel: string; arkivdel_tekst: string; klasse: string; klasse_tekst: string;
  enhet: string; saksansvarlig: string; saksansvarlig_navn: string; status: string; saksdato: string; fulgte_med: boolean; antall: number;
};
export type Journalpost = {
  id: string; sak_id: string; postnr: number; lopenummer: string; journalfort: boolean; type: string; status: string; tittel: string; offentlig_tittel: string | null;
  dokumentdato: string; journaldato: string; saksbehandler: string; enhet: string; tilgangskode: string | null; hjemmel: string; skjerm_tittel: boolean; skjerm_part: boolean;
  part_navn: string; oppgave_id: string | null; innhold?: string | null; saksnummer?: string; sakstittel?: string;
};
export type Behandling = {
  dokument: Dokument; behandlet: boolean; handling?: Handling; vurdering?: Kriterium[]; poeng?: number; maks?: number;
  forslag?: { sak: string | null; sak_offentlig: string | null; tittel: string | null; offentlig_tittel: string | null } | null;
  journalpost?: Journalpost | null; sak?: Sak | null;
};
export type Struktur = {
  arkivdeler: { kode: string; tittel: string; beskrivelse: string }[];
  klasser: { kode: string; tittel: string; forelder: string | null }[];
  enheter: { kode: string; navn: string }[];
  ansatte: { initialer: string; navn: string; tittel: string; enhet: string }[];
  tilgangskoder: { kode: string; navn: string; hjemmel: string }[];
  journalposttyper: string[];
  handlinger: Handling[];
};
export type Oversikt = { saker: number; journalposter: number; behandlet: number; poeng: number; maks: number; dokumenter: number };

let lagret: Struktur | null = null;

/** Arkivstrukturen (arkivdeler, arkivnøkkel, enheter, ansatte og tilgangskoder). Hentes første gang den trengs. */
export function useStruktur(): Struktur | null {
  const [struktur, sett] = useState<Struktur | null>(lagret);
  useEffect(() => {
    if (lagret) return;
    let aktiv = true;
    api.get<Struktur>('/api/arkiv/struktur').then((s) => { lagret = s; if (aktiv) sett(s); }).catch(() => { /* siden viser feilen fra sitt eget kall */ });
    return () => { aktiv = false; };
  }, []);
  return struktur;
}
