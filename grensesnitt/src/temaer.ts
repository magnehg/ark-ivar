// bivrost: fargekilde (nøkkelfargene til temaene og basisfargene for status, data og ArchiMate)
// Fargetemaene og valgene for utseendet (B-040, B-041, B-049, BIV-GRS-01, BIV-PRF-01). Nøkkelfargene er fra den
// web-sikre paletten. Alt annet beregnes i tokens.ts og kontrolleres av skript/sjekk-kontrast.ts.

export type TemaId = 'marine' | 'teal' | 'mose' | 'arkivbrun' | 'vinrod';
export type Modus = 'lys' | 'mork';
export type ModusValg = 'system' | Modus;
export type Kontrast = 'normal' | 'hoy';
export type Bredde = 'begrenset' | 'full';
export type Tetthet = 'romslig' | 'kompakt';
export type Nokkelfarger = { hoved: string; aksent: string; lys: string };

export const TEMAER: Record<TemaId, Nokkelfarger> = {
  marine: { hoved: '#003366', aksent: '#33CCFF', lys: '#CCFFFF' },
  teal: { hoved: '#336666', aksent: '#99CCCC', lys: '#CCFFFF' },
  mose: { hoved: '#336633', aksent: '#CCCC99', lys: '#FFFFCC' },
  arkivbrun: { hoved: '#663300', aksent: '#FFCC66', lys: '#FFFFCC' },
  vinrod: { hoved: '#990033', aksent: '#FF9999', lys: '#FFCCCC' },
};
export const TEMA_REKKEFOLGE: TemaId[] = ['marine', 'teal', 'mose', 'arkivbrun', 'vinrod'];
export const MODUSER: Modus[] = ['lys', 'mork'];
export const KONTRASTER: Kontrast[] = ['normal', 'hoy'];
export const STANDARD = { tema: 'marine' as TemaId, modus: 'system' as ModusValg, bredde: 'begrenset' as Bredde, tetthet: 'romslig' as Tetthet };

/** Statusfargene (kulør). Vises alltid sammen med ikon og tekst, aldri bare som farge (WCAG 1.4.1). */
export const STATUS = {
  ok: '#107C10',
  advarsel: '#C25E00',
  feil: '#C50F1F',
  info: '#0F6CBD',
  pagar: '#5C2E91',
  noytral: '#616161',
} as const;
export type Status = keyof typeof STATUS;

/**
 * Kategorisk palett for diagrammer: Okabe og Ito (2008), laget for å kunne skilles av de fleste med fargesynsavvik.
 * Lysheten justeres per modus til minst 3:1 mot flatene (WCAG 1.4.11).
 */
export const KATEGORISK = ['#0072B2', '#E69F00', '#009E73', '#CC79A7', '#56B4E9', '#D55E00', '#F0E442', '#000000'];

/** Divergerende skala blå–nøytral–oransje (fargeblindvennlig, unngår rød–grønn). */
export const DIVERGERENDE = { lav: '#2166AC', midt: '#F7F7F7', hoy: '#B35806' };

/**
 * ArchiMate-lagene med fargene fra ArchiMate 3.2-spesifikasjonens eksempler (forretning gul, applikasjon turkis,
 * teknologi og fysisk grønn, motivasjon lilla, implementering og migrering rosa/grønn, strategi oransje).
 */
export const ARCHIMATE = {
  strategi: '#F5DEAA',
  forretning: '#FFFFB5',
  applikasjon: '#B5FFFF',
  teknologi: '#C9E7B7',
  fysisk: '#C9E7B7',
  motivasjon: '#CCCCFF',
  implementering: '#FFE0E0',
} as const;
export type ArchiMateLag = keyof typeof ARCHIMATE;
