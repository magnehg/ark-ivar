// Språkfilene i grensesnittet (BIV-SPR-01–06), samme oppsett som Gerð: én JSON-fil per modul og språk
// (i18n/<språk>/bivrost-<modul>.json) med ICU MessageFormat og nøkler med prefikset «bivrost.». Appen laster dem med
// Vite (import.meta.glob) og gir dem til react-intl sammen med sine egne:
//   const filer = import.meta.glob('../../bivrost/kjerne/i18n/*/*.json', { eager: true });
//   const meldinger = slaSammen(filer, 'nn', 'nb');
// useT()-hjelperen og IntlProvider-oppsettet kommer med komponentene (fase 3).

type Fil = { default: Record<string, string> } | Record<string, string>;

/** Språket i stien …/i18n/<språk>/<fil>.json, eller null. */
export function sprakFraSti(sti: string): string | null {
  const m = /\/i18n\/([a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*)\/[^/]+\.json$/.exec(sti);
  return m ? m[1] : null;
}

/**
 * Slår sammen filene for et språk, med hovedspråket under (tekster som mangler, vises på hovedspråket, SPR-05).
 * Gir også hvilke nøkler som kom fra hovedspråket, så visningen kan sette lang-attributtet på dem.
 */
export function slaSammen(filer: Record<string, Fil>, sprak: string, hovedsprak = 'nb'): { meldinger: Record<string, string>; fraHovedsprak: Set<string> } {
  const per: Record<string, Record<string, string>> = {};
  for (const [sti, f] of Object.entries(filer)) {
    const s = sprakFraSti(sti);
    if (s === null) continue;
    const innhold = ('default' in f && typeof f.default === 'object' ? f.default : f) as Record<string, string>;
    per[s] = { ...(per[s] ?? {}), ...innhold };
  }
  const hoved = per[hovedsprak] ?? {};
  const valgt = per[sprak] ?? {};
  const fraHovedsprak = new Set(Object.keys(hoved).filter((k) => !(k in valgt)));
  return { meldinger: { ...hoved, ...valgt }, fraHovedsprak };
}

/** Språket som skal brukes: brukerens preferanse → appens → nettleserens → hovedspråket (BIV-SPR-04). */
export function velgSprak(tilgjengelige: string[], ...onsker: (string | null | undefined)[]): string {
  for (const o of onsker) {
    for (const del of (o ?? '').split(',')) {
      const kode = del.split(';')[0].trim().toLowerCase();
      if (kode === '') continue;
      const treff = tilgjengelige.find((t) => t.toLowerCase() === kode) ?? tilgjengelige.find((t) => t.toLowerCase() === kode.split('-')[0]) ?? (kode.startsWith('no') ? tilgjengelige.find((t) => t === 'nb') : undefined);
      if (treff) return treff;
    }
  }
  return tilgjengelige[0] ?? 'nb';
}
