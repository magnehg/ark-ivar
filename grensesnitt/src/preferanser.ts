// Brukerens preferanser for utseendet i nettleseren (BIV-PRF-04, BIV-PRF-05). Sannheten er hos Bivrost Sentral
// (og Kjerne husker siste verdier i appens database); her settes attributtene på <html> før siden tegnes, og siste
// valg huskes i localStorage, så utseendet ikke blinker eller faller tilbake når Sentral er nede.
// localStorage kan mangle eller kaste (privat modus, blokkert lagring): alt er i try/catch, og siden virker uten.
// Fargene for hvert tema ligger i en egen stilfil (stil/tema/<tema>.css, BIV-B-028), lenket med
// <link rel="stylesheet" data-bivrost-tema-fil="aktiv" href="…/stil/tema/<tema>.css">. Det er filen som er lastet som
// bestemmer fargene. byttTemaFil() henter den nye filen ved siden av den gamle og fjerner den gamle først når den nye er
// lastet, så siden ikke blinker; feiler hentingen, beholdes den gamle.

import { TEMAER, type TemaId } from './temaer.ts';

export type Utseende = {
  tema?: string; modus?: 'system' | 'lys' | 'mork'; bredde?: 'begrenset' | 'full'; tetthet?: 'romslig' | 'kompakt';
  redusertBevegelse?: 'system' | 'ja' | 'nei'; hoyKontrast?: 'system' | 'ja' | 'nei'; preferredLanguage?: string;
};
type Lager = { getItem(k: string): string | null; setItem(k: string, v: string): void };
type Element = { setAttribute(n: string, v: string): void; removeAttribute(n: string): void };
export type Lenke = {
  getAttribute(n: string): string | null; setAttribute(n: string, v: string): void;
  cloneNode(dyp: boolean): unknown; after(...n: unknown[]): void; remove(): void;
  addEventListener(type: 'load' | 'error', f: () => void, valg?: { once?: boolean }): void;
};
export type Dokument = { querySelectorAll(s: string): ArrayLike<Lenke> };
const FIL = 'data-bivrost-tema-fil';

const NOKKEL = 'bivrost:utseende';
const GYLDIG: Record<string, readonly string[]> = {
  modus: ['system', 'lys', 'mork'], bredde: ['begrenset', 'full'], tetthet: ['romslig', 'kompakt'],
  redusertBevegelse: ['system', 'ja', 'nei'], hoyKontrast: ['system', 'ja', 'nei'],
};

/** Bare kjente felt med gyldige verdier (data fra lagringen kan være gamle eller endret for hånd). */
export function rens(x: unknown): Utseende {
  if (x === null || typeof x !== 'object') return {};
  const ut: Record<string, string> = {};
  for (const [k, v] of Object.entries(x as Record<string, unknown>)) {
    if (typeof v !== 'string') continue;
    if (k === 'tema' && /^[a-z]{1,20}$/.test(v)) ut[k] = v;
    else if (k === 'preferredLanguage' && /^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/.test(v)) ut[k] = v;
    else if (GYLDIG[k]?.includes(v)) ut[k] = v;
  }
  return ut as Utseende;
}

export function les(lager: Lager | undefined): Utseende {
  try {
    const v = lager?.getItem(NOKKEL);
    return v ? rens(JSON.parse(v)) : {};
  } catch {
    return {};
  }
}

export function husk(lager: Lager | undefined, u: Utseende): boolean {
  try {
    lager?.setItem(NOKKEL, JSON.stringify(rens(u)));
    return lager !== undefined;
  } catch {
    return false;
  }
}

/** Temaet som skal brukes: brukerens valg når det finnes i designpakken, ellers Marine og turkis. */
export function tema(u: Utseende): TemaId {
  const t = rens(u).tema;
  return t !== undefined && Object.hasOwn(TEMAER, t) ? (t as TemaId) : 'marine';
}

/** Adressen til temaets stilfil, relativt til mappen med designpakken (f.eks. «/bivrost/ressurser/»). */
export function temaFil(u: Utseende, rot = '/bivrost/ressurser/'): string {
  return `${rot.endsWith('/') ? rot : `${rot}/`}stil/tema/${tema(u)}.css`;
}

/**
 * Bytter temaets stilfil til `href`. Den nye lenken (`data-bivrost-tema-fil="venter"`) legges etter den aktive; når den er
 * lastet, fjernes den gamle og den nye blir aktiv. Feiler hentingen, fjernes den nye og den gamle beholdes. Et bytte som
 * ikke er ferdig når et nytt kommer, avbrytes. Samme adresse som den aktive gjør ingenting.
 */
export function byttTemaFil(dok: Dokument, href: string): void {
  const lenker = Array.from(dok.querySelectorAll(`link[${FIL}]`));
  const aktiv = lenker.find((l) => l.getAttribute(FIL) === 'aktiv');
  for (const l of lenker) if (l !== aktiv) l.remove();
  if (!aktiv || aktiv.getAttribute('href') === href) return;
  const ny = aktiv.cloneNode(false) as Lenke;
  ny.setAttribute(FIL, 'venter');
  ny.setAttribute('href', href);
  ny.addEventListener('load', () => { aktiv.remove(); ny.setAttribute(FIL, 'aktiv'); }, { once: true });
  ny.addEventListener('error', () => ny.remove(), { once: true });
  aktiv.after(ny);
}

/**
 * Setter attributtene stilfilene bruker (se docs/designtokens.md). Med `dok` (document) byttes også temaets stilfil
 * (byttTemaFil), så fargene følger temaet.
 */
export function bruk(el: Element, u: Utseende, dok?: Dokument, rot?: string): void {
  const r = rens(u);
  el.setAttribute('data-bivrost-tema', tema(r));
  if (dok) byttTemaFil(dok, temaFil(r, rot));
  el.setAttribute('data-bivrost-modus', r.modus ?? 'system');
  el.setAttribute('data-bivrost-bredde', r.bredde ?? 'begrenset');
  el.setAttribute('data-bivrost-tetthet', r.tetthet ?? 'romslig');
  el.setAttribute('data-bivrost-kontrast', r.hoyKontrast === 'ja' ? 'hoy' : r.hoyKontrast === 'nei' ? 'normal' : 'system');
  el.setAttribute('data-bivrost-bevegelse', r.redusertBevegelse ?? 'system');
  if (r.preferredLanguage) el.setAttribute('lang', r.preferredLanguage); else el.removeAttribute('lang');
}
