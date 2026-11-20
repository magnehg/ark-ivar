// Klient for API-et til Ark-Ivar (/api/…) og Bivrost Kjerne (/bivrost/api/…). All tilgangskontroll skjer på serveren.
// Endringer må ha CSRF-nøkkelen fra økten i hodet X-CSRF. Nøkkelen kommer i svaret fra GET /bivrost/api/okt og på nytt
// etter hver innlogging (økten får da ny ID), så klienten tar vare på den siste den har sett.

/** Et feilsvar fra serveren (RFC 9457). «melding» er teksten fra serveren på brukerens språk, tom når svaret ikke hadde noen. */
export class ApiFeil extends Error {
  constructor(melding: string, public status: number, public kode?: string) { super(melding); }
  melding(): string { return this.message; }
}

let csrf = '';

async function kall<T>(metode: string, sti: string, kropp?: unknown, hoder: Record<string, string> = {}): Promise<T> {
  const svar = await fetch(sti, {
    method: metode,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(kropp !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(metode !== 'GET' && csrf ? { 'X-CSRF': csrf } : {}),
      ...hoder,
    },
    body: kropp !== undefined ? JSON.stringify(kropp) : undefined,
  });
  const tekst = await svar.text();
  let data: Record<string, unknown> | null = null;
  try { data = tekst ? (JSON.parse(tekst) as Record<string, unknown>) : null; } catch { data = null; }
  if (data && typeof data.csrf === 'string') csrf = data.csrf;
  if (!svar.ok) {
    const melding = typeof data?.detail === 'string' ? data.detail : '';
    throw new ApiFeil(melding, svar.status, typeof data?.kode === 'string' ? data.kode : undefined);
  }
  return (data ?? {}) as T;
}

export const api = {
  get: <T,>(sti: string, hoder?: Record<string, string>) => kall<T>('GET', sti, undefined, hoder),
  post: <T,>(sti: string, kropp?: unknown, hoder?: Record<string, string>) => kall<T>('POST', sti, kropp ?? {}, hoder),
  put: <T,>(sti: string, kropp?: unknown) => kall<T>('PUT', sti, kropp ?? {}),
  slett: <T,>(sti: string) => kall<T>('DELETE', sti),
};

// ------------------------------------------------------------------ typer

export type Tilgang = { urn: string; navn: string };
/** Den innloggede slik Kjerne beskriver den (GET /bivrost/api/okt). */
export type Innlogget = { type: string; id: string | null; navn: string; tittel: string | null; metode: 'app' | 'lokal' | 'oidc'; tilganger: Tilgang[] };
export type Okt = { innlogget: Innlogget | null; csrf: string; metoder?: { sentral: boolean; lokal: boolean; app: boolean } };
export type Oppsett = { appnavn: string; virksomhet: string; versjon: string; sprak: string; passord: { min: number; maks: number }; steder: { sted: string; domene: string }[] };
export type Bruker = { id: string; epost: string; navn: string; rolle: Rolle; aktiv: boolean; epost_bekreftet: boolean; registrert_via: string; opprettet: string | null; sist_innlogget: string | null };
export type Rolle = 'student' | 'laerer' | 'administrator';
export type Klasse = { id: string; navn: string; aktiv: boolean; laerer: string | null; kode?: string; laerer_id?: string; antall?: number };
export type Klassestudent = { id: string; navn: string; epost: string; innmeldt: string | null; sist_innlogget: string | null };
export type Domene = { id: string; domene: string; sted: string; underdomener: boolean; aktiv: boolean };

const ROLLE = 'urn:bivrost:app:arkivar:rolle:';
export const DRIFT = 'urn:bivrost:tilgang:drift';
/** Om den innloggede har rollen (rollene er et hierarki, så en lærer har også «student»). */
export const harRolle = (i: Innlogget | null, rolle: Rolle) => !!i?.tilganger.some((t) => t.urn === ROLLE + rolle);
export const erDrift = (i: Innlogget | null) => !!i?.tilganger.some((t) => t.urn === DRIFT);
/** Brukere og godkjente domener styres av administratoren, og av drift (som lager den første administratoren). */
export const kanAdministrere = (i: Innlogget | null) => harRolle(i, 'administrator') || erDrift(i);
