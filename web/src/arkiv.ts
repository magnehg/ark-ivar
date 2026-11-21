// Typene for øvingsarkivet (API-et under /api/arkiv/) og arkivstrukturen, som er lik for alle og hentes én gang.
import { useHent } from './komponenter/Felles';

export type Handling = 'journalfor' | 'arkiver' | 'fagsystem' | 'ikke_arkiver';
export type Postmottaksrad = { id: string; kanal: string; mottatt: string; emne: string; avsender: string; behandlet: boolean; handling: Handling | null; poeng: number | null; maks: number | null };
/** Et dokument slik det vises som et ark: fra postmottaket, eller et som fulgte med arkivet. */
export type Dokument = {
  id?: string; kanal: string; mottatt: string | null; dokumentdato: string; emne: string; tekst: string[]; vedlegg: string[];
  avsender: { navn: string; adresse: string | null; epost: string | null }; mottaker: string | null;
  /** Saksbehandlerens første utkast (versjon 1), for dokumentene som har to versjoner. */
  utkast?: string[];
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
/** En dokumentfil: versjonen av dokumentet, variantformatet (P = produksjonsformat, A = arkivformat), filtypen og filnavnet. */
export type Dokumentfil = { versjon: number; variantformat: 'P' | 'A'; format: 'docx' | 'eml' | 'tif' | 'pdf' | 'txt'; filnavn: string };
/** Et dokument i en journalpost: hoveddokumentet (H) eller et vedlegg (V), med filene sine. */
export type Dokumentbeskrivelse = { nr: number; rolle: 'H' | 'V'; tittel: string; filer: Dokumentfil[] };
export type Journalpost = {
  id: string; sak_id: string; postnr: number; lopenummer: string; journalfort: boolean; type: string; status: string; tittel: string; offentlig_tittel: string | null;
  dokumentdato: string; journaldato: string; saksbehandler: string; saksbehandler_navn: string; enhet: string; tilgangskode: string | null; hjemmel: string; skjerm_tittel: boolean; skjerm_part: boolean;
  part_navn: string; har_dokument: boolean; saksnummer?: string; sakstittel?: string; klasse_tekst?: string; skjerm_klasse?: boolean;
  /** Hjemmelen for skjermingen på mappen (tittel eller klassering), når mappen er skjermet. */
  sak_skjermet?: boolean; sak_hjemmel?: string;
  /** Dokumentet fra postmottaket registreringen gjelder. Uten er den studentens egen, og kan slettes. */
  oppgave: string | null; kan_slettes: boolean;
  /** Statusen på dokumentfilen (Noark: dokumentstatus og variantformat). Null når registreringen ikke har noe dokument. */
  dokumentstatus: string | null; variantformat: string | null;
  /** Filene til hoveddokumentet. Tom i den offentlige journalen og for registreringer uten dokument. */
  filer: Dokumentfil[];
  /** Hoveddokumentet og vedleggene. Tom i den offentlige journalen og for registreringer uten dokument. */
  dokumenter: Dokumentbeskrivelse[];
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
  /** Statuskodene fra Noark 5 for mappen, journalposten og dokumentfilen. Navnene står i språkfilene. */
  saksstatuser: string[];
  journalstatuser: string[];
  dokumentstatuser: string[];
  variantformater: string[];
};

/** Kodelistene med status: tekstnøklene er arkivar.<liste>.<kode> (navnet) og arkivar.<liste>.<kode>.bruk (når koden brukes). */
export type Statusliste = 'saksstatus' | 'jpstatus' | 'dokstatus' | 'variantformat';
/** utdatert: arkivet ble fylt med en eldre utgave av mappene og oppgavene, og bør nullstilles. */
export type Oversikt = { saker: number; journalposter: number; behandlet: number; poeng: number; maks: number; dokumenter: number; utdatert: boolean };

let lagret: Struktur | null = null;

/**
 * Arkivstrukturen (arkivdeler, arkivnøkkel, enheter, ansatte og tilgangskoder). Hentes første gang den trengs og
 * huskes til siden lastes på nytt. Lar den seg ikke hente, har siden feilen og kan prøve igjen med last().
 */
/** Glemmer strukturen, så den hentes på nytt: etter en nullstilling, og når arkivet er fylt med en eldre utgave. */
export function glemStruktur(): void {
  lagret = null;
}

export function useStruktur(): { data: Struktur | null; feil: string | null; last: () => void } {
  const hent = useHent<Struktur>(lagret ? null : '/api/arkiv/struktur');
  if (hent.data) lagret = hent.data;
  return { data: lagret, feil: lagret ? null : hent.feil, last: hent.last };
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
/** ID-ene til dokumentene i postmottaket. Alt annet i adressen er ikke et dokument, og blir ikke brukt. */
const DOKUMENT_ID = /^[a-z0-9-]{1,60}$/;
export function lesFlyt(sok: URLSearchParams): Flyt {
  const dokument = sok.get('dokument');
  const handling = sok.get('handling');
  return dokument && DOKUMENT_ID.test(dokument) && (handling === 'journalfor' || handling === 'arkiver') ? { dokument, handling } : null;
}
/** En ID fra adressen som en del av en sti til API-et. Den kodes, så den aldri kan peke til en annen adresse. */
export const del = (id: string): string => encodeURIComponent(id);
export const flytSok = (flyt: Flyt): string => (flyt ? `?dokument=${encodeURIComponent(flyt.dokument)}&handling=${flyt.handling}` : '');

/** Filen dokumentet vises med i en liste: arkivformatet når det finnes, ellers produksjonsformatet. */
export const hovedfil = (filer: Dokumentfil[]): Dokumentfil | null => filer.find((f) => f.variantformat === 'A') ?? filer[0] ?? null;
