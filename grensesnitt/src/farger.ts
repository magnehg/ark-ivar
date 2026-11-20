// bivrost: fargekilde (fargeregning; faste verdier her er data, ikke utseende)
// Fargeregning for designpakken (BIV-GRS-03): OKLab for jevne skalaer, og WCAG 2.2-luminans og kontrast for
// kontrollen. Ren TypeScript uten avhengigheter, så generatoren og kontrastsjekken kan kjøres med Node uten bygg.

export type Lab = [number, number, number];

const tilLin = (c: number) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const fraLin = (c: number) => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055);

export function hexTilRgb(h: string): [number, number, number] {
  if (!/^#[0-9a-fA-F]{6}$/.test(h)) throw new Error(`Ugyldig farge: ${h}`);
  return [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255) as [number, number, number];
}

export function rgbTilHex(rgb: number[]): string {
  return '#' + rgb.map((v) => Math.round(Math.min(1, Math.max(0, v)) * 255).toString(16).padStart(2, '0')).join('').toUpperCase();
}

export function tilOklab(hex: string): Lab {
  const [r, g, b] = hexTilRgb(hex).map(tilLin);
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  return [
    0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
    1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
    0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
  ];
}

export function fraOklab([L, a, b]: Lab): string {
  const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
  return rgbTilHex([
    4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  ].map(fraLin));
}

export const blandLab = (a: Lab, b: Lab, t: number): Lab => a.map((v, i) => v + (b[i] - v) * t) as Lab;

/** Blander to farger i sRGB, som color-mix(in srgb, a t, b). t = andelen av a. */
export function blandHex(a: string, b: string, t: number): string {
  const x = hexTilRgb(a), y = hexTilRgb(b);
  return rgbTilHex(x.map((v, i) => v * t + y[i] * (1 - t)));
}

/** Relativ luminans etter WCAG 2.2. */
export function luminans(hex: string): number {
  const [r, g, b] = hexTilRgb(hex).map(tilLin);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Kontrastforholdet etter WCAG 2.2 (1–21). */
export function kontrast(a: string, b: string): number {
  const [x, y] = [luminans(a), luminans(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

/**
 * Justerer lysheten (OKLab L) til fargen har minst `krav` i kontrast mot alle bakgrunnene, i retningen som gir mest
 * kontrast (mørkere på lys bakgrunn, lysere på mørk). Kulør og metning beholdes så langt det går. Gir fargen
 * uendret når den alt holder kravet.
 */
export function juster(hex: string, bakgrunner: string[], krav: number, margin = 0.05): string {
  const holder = (h: string) => bakgrunner.every((b) => kontrast(h, b) >= krav + margin);
  if (holder(hex)) return hex;
  const lys = bakgrunner.reduce((s, b) => s + luminans(b), 0) / bakgrunner.length > 0.18;
  const [L, a, b] = tilOklab(hex);
  for (let i = 1; i <= 200; i++) {
    const nyL = lys ? L - (L * i) / 200 : L + ((1 - L) * i) / 200;
    const demp = 1 - (i / 200) * 0.5; // mindre metning mot svart og hvitt, så fargen holder seg i sRGB
    const h = fraOklab([nyL, a * demp, b * demp]);
    if (holder(h)) return h;
  }
  return lys ? '#000000' : '#FFFFFF';
}

/** Den av svart og hvitt (eller to gitte farger) som gir mest kontrast mot bakgrunnen. */
export function bestAv(bakgrunn: string, valg: string[] = ['#000000', '#FFFFFF']): string {
  return valg.reduce((best, f) => (kontrast(f, bakgrunn) > kontrast(best, bakgrunn) ? f : best));
}
