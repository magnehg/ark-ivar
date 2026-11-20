// Fluent UI v9-temaet fra designtokenene (BIV-GRS-02). Appen henter tokenfilene (grunnlaget og fargefilen for valgt
// tema, modus og kontrast) og lager temaet slik:
//   import { webLightTheme } from '@fluentui/react-components';
//   const tema = { ...webLightTheme, ...fluentTema(grunnlag, farger) };
// Ingen import av Fluent her, så modulen kan testes uten React. Fargene står med Fluents navn under «fluent» i
// fargefilen; grunnlaget har Fluent-navnet i $extensions["no.bivrost"].fluent.

type Token = { $type?: string; $value: unknown; $extensions?: Record<string, unknown> };
type Dok = Record<string, unknown>;

function* tokener(g: Dok, sti: string[] = []): Generator<[string[], Token]> {
  for (const [k, v] of Object.entries(g)) {
    if (k.startsWith('$') || v === null || typeof v !== 'object') continue;
    if ('$value' in (v as object)) yield [[...sti, k], v as Token];
    else yield* tokener(v as Dok, [...sti, k]);
  }
}

function verdi(t: Token): string | number {
  const v = t.$value;
  if (t.$type === 'fontFamily' && Array.isArray(v)) return v.map((x) => (/[\s]/.test(String(x)) || /^[A-Z]/.test(String(x)) ? `'${x}'` : String(x))).join(', ');
  if (t.$type === 'cubicBezier' && Array.isArray(v)) return `cubic-bezier(${v.join(',')})`;
  if (t.$type === 'fontWeight' && typeof v === 'number') return v;
  return String(v);
}

/** Fluent-tokenene (navn → verdi) fra grunnlaget og en fargefil. */
export function fluentTema(grunnlag: Dok, farger: Dok): Record<string, string | number> {
  const ut: Record<string, string | number> = {};
  for (const [, t] of tokener(grunnlag)) {
    const fl = (t.$extensions?.['no.bivrost'] as { fluent?: string | string[] } | undefined)?.fluent;
    for (const n of fl === undefined ? [] : Array.isArray(fl) ? fl : [fl]) ut[n] = verdi(t);
  }
  const fl = (farger.fluent ?? {}) as Dok;
  for (const [sti, t] of tokener(fl)) ut[sti.join('')] = String(t.$value);
  return ut;
}

/** Navnet på fargefilen for et valg, f.eks. tokenfil('marine', 'mork', 'hoy') → «marine.mork.hoy.tokens.json». */
export function tokenfil(tema: string, modus: 'lys' | 'mork', kontrast: 'normal' | 'hoy'): string {
  if (!/^[a-z]+$/.test(tema)) throw new Error('Ugyldig tema.');
  return `${tema}.${modus}.${kontrast}.tokens.json`;
}
