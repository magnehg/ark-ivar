// Typene for øvingsarkivet (API-et under /api/arkiv/) og arkivstrukturen, som er lik for alle og hentes én gang.
import { useEffect, useState } from 'react';
import { api } from './api';

export type Handling = 'journalfor' | 'arkiver' | 'fagsystem' | 'ikke_arkiver';
export type Postmottaksrad = { id: string; kanal: string; mottatt: string; emne: string; avsender: string; behandlet: boolean; handling: Handling | null; poeng: number | null; maks: number | null };
/** Et dokument slik det vises som et ark: fra postmottaket, eller et som fulgte med arkivet. */
export type Dokument = {
  id?: string; kanal: string; mottatt: string | null; dokumentdato: string; emne: string; tekst: string[]; vedlegg: string[];
  avsender: { navn: string; adresse: string | null; epost: string | null }; mottaker: string | null;
};
export type Kriterium = { id: string; ok: boolean; ditt: string; riktig: string; forklaring: string };
/** En mappe. klasse er primærklasseringen; skjerm_klasse sier at den ikke vises i den offentlige journalen. */
export type Sak = {
  id: string; saksnummer: string; tittel: string; skjerm_tittel: boolean; offentlig_tittel: string | null; arkivdel: string; arkivdel_tekst: string; klasse: string; klasse_tekst: string;
  skjerm_klasse: boolean; tilgangskode: string | null; hjemmel: string; mal: string | null;
  enhet: string; saksansvarlig: string; saksansvarlig_navn: string; status: string; saksdato: string; fulgte_med: boolean; antall: number;
};
/** Et treff i søket: mappen, og dokumentene i den som har et av søkeordene. */
export type Sokestreff = { sak: Sak; journalposter: { postnr: number; tittel: string; part_navn: string }[] };
export type Journalpost = {
  id: string; sak_id: string; postnr: number; lopenummer: string; journalfort: boolean; type: string; status: string; tittel: string; offentlig_tittel: string | null;
  dokumentdato: string; journaldato: string; saksbehandler: string; enhet: string; tilgangskode: string | null; hjemmel: string; skjerm_tittel: boolean; skjerm_part: boolean;
  part_navn: string; har_dokument: boolean; saksnummer?: string; sakstittel?: string; klasse_tekst?: string; skjerm_klasse?: boolean;
  /** Dokumentet fra postmottaket registreringen gjelder. Uten er den studentens egen, og kan slettes. */
  oppgave: string | null; kan_slettes: boolean;
};
export type Behandling = {
  dokument: Dokument; behandlet: boolean; handling?: Handling; vurdering?: Kriterium[]; poeng?: number; maks?: number;
  forslag?: { sak: string | null; sak_offentlig: string | null; tittel: string | null; offentlig_tittel: string | null } | null;
  journalpost?: Journalpost | null; sak?: Sak | null;
};
export type Ordning = 'emne' | 'eiendom' | 'person' | 'utvalg';
export type Arkivdel = { kode: string; tittel: string; ordning: Ordning; mappe: string; beskrivelse: string };
export type Klasse = { kode: string; tittel: string; forelder: string | null; merknad: string | null; henvisning: string | null };
/** En post i et register. For personer er koden fødselsdato og navn, og fnr er det oppdiktede fødselsnummeret. */
export type Registerpost = { kode: string; tittel: string; detalj: string; fodt: string | null; fnr: string | null };
/** En saksmal: arkivdelen og et forslag til tittel er fylt inn når mappen opprettes. */
export type Saksmal = { kode: string; navn: string; arkivdel: string; tittel: string; beskrivelse: string; soketips: string };
export type Struktur = {
  arkivdeler: Arkivdel[];
  /** Arkivnøkkelen. En klasse med henvisning viser til en annen arkivdel og kan ikke velges i sakarkivet. */
  klasser: Klasse[];
  /** Registeret hver arkivdel klassifiseres på (ikke sakarkivet, som bruker arkivnøkkelen). */
  registre: Record<string, Registerpost[]>;
  saksmaler: Saksmal[];
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

export type Klassegruppe = { gruppe: string; klasser: Klasse[] };

/**
 * Klassene en mappe kan legges på i arkivnøkkelen: de som ikke har underklasser og ikke viser til en annen
 * arkivdel, gruppert under klassen over. Med et søk tas bare de med som har søket i koden (fra starten), tittelen,
 * merknaden eller tittelen på gruppen over.
 */
export function valgbareKlasser(struktur: Struktur, sok = ''): Klassegruppe[] {
  const foreldre = new Set(struktur.klasser.map((k) => k.forelder));
  const ord = sok.trim().toLocaleLowerCase('nb');
  const grupper = new Map<string, Klassegruppe>();
  for (const k of struktur.klasser) {
    if (k.forelder === null || k.henvisning !== null || foreldre.has(k.kode)) continue;
    const over = struktur.klasser.find((x) => x.kode === k.forelder);
    const navn = over ? `${over.kode} ${over.tittel}` : k.forelder;
    if (ord && !k.kode.startsWith(ord) && !`${k.tittel} ${k.merknad ?? ''} ${over?.tittel ?? ''}`.toLocaleLowerCase('nb').includes(ord)) continue;
    if (!grupper.has(navn)) grupper.set(navn, { gruppe: navn, klasser: [] });
    grupper.get(navn)!.klasser.push(k);
  }
  return [...grupper.values()];
}

/** En post i et register slik den vises i en liste: «9/112 Fyrveien 8», og fødselsdato og navn for personer. */
export function registertekst(del: Arkivdel, post: Registerpost): string {
  return del.ordning === 'person' ? post.kode : `${post.kode} ${post.tittel}`;
}

/** Postene i registeret som har søket i koden, navnet eller opplysningene. */
export function registertreff(poster: Registerpost[], sok: string): Registerpost[] {
  const ord = sok.trim().toLocaleLowerCase('nb');
  return ord ? poster.filter((p) => `${p.kode} ${p.tittel} ${p.detalj}`.toLocaleLowerCase('nb').includes(ord)) : poster;
}

/** Det som følger med i adressen mens et dokument fra postmottaket registreres: dokumentet og behandlingen som er valgt. */
export type Flyt = { dokument: string; handling: Handling } | null;
export function lesFlyt(sok: URLSearchParams): Flyt {
  const dokument = sok.get('dokument');
  const handling = sok.get('handling');
  return dokument && (handling === 'journalfor' || handling === 'arkiver') ? { dokument, handling } : null;
}
export const flytSok = (flyt: Flyt): string => (flyt ? `?dokument=${encodeURIComponent(flyt.dokument)}&handling=${flyt.handling}` : '');
