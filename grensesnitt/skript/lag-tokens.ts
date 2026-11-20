// Skriver designtokenene, stilfilen og oversikten (BIV-GRS-01–06):
//   tokens/grunnlag.tokens.json                       grunnlaget (W3C Design Tokens-formatet)
//   tokens/tema/<tema>.<modus>.<kontrast>.tokens.json fargene, 5 temaer × lys/mørk × normal/høy kontrast
//   tokens/style-dictionary.config.json               oppsett for Style Dictionary (v4 leser formatet direkte)
//   stil/bivrost.css                                  grunnfilen: CSS-variabler, skrift, fokus, bevegelse, utskrift
//   stil/tema/<tema>.css                              fargene for ett tema i lys, mørk og høy kontrast (BIV-B-028)
//   ../docs/designtokens.md                           oversikten
// Kjør: node --experimental-strip-types skript/lag-tokens.ts          (skriver filene)
//       node --experimental-strip-types skript/lag-tokens.ts --sjekk  (feiler hvis filene ikke er i takt; kjøres i CI)
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { flat, grunnlag, slaaOpp, temaTokens, type Gruppe, type Token } from './generator.ts';
import { KONTRASTER, MODUSER, TEMA_REKKEFOLGE, type Kontrast, type Modus, type TemaId } from '../src/temaer.ts';

const rot = join(dirname(fileURLToPath(import.meta.url)), '..');
const filer = new Map<string, string>();
const json = (d: unknown) => JSON.stringify(d, null, 2) + '\n';

const g = grunnlag();
filer.set('tokens/grunnlag.tokens.json', json(g));
const temaer: { id: TemaId; modus: Modus; k: Kontrast; dok: Gruppe }[] = [];
for (const id of TEMA_REKKEFOLGE) for (const modus of MODUSER) for (const k of KONTRASTER) {
  const dok = temaTokens(id, modus, k);
  temaer.push({ id, modus, k, dok });
  filer.set(`tokens/tema/${id}.${modus}.${k}.tokens.json`, json(dok));
}
filer.set('tokens/style-dictionary.config.json', json({
  $comment: 'Style Dictionary v4 (DTCG-formatet). Eksempel: npx style-dictionary build --config tokens/style-dictionary.config.json',
  source: ['tokens/grunnlag.tokens.json', 'tokens/tema/marine.lys.normal.tokens.json'],
  platforms: {
    css: { transformGroup: 'css', prefix: 'bivrost', buildPath: 'bygg/style-dictionary/', files: [{ destination: 'variabler.css', format: 'css/variables' }] },
    json: { transformGroup: 'js', buildPath: 'bygg/style-dictionary/', files: [{ destination: 'tokens.json', format: 'json/flat' }] },
  },
}));

// ---------------------------------------------------------------- CSS
const navn = (sti: string[]) => '--bivrost-' + sti.join('-');
function cssVerdi(t: Token, dok: Gruppe): string {
  const v = slaaOpp(dok, t.$value);
  switch (t.$type) {
    case 'fontFamily': return (v as string[]).map((x) => (/[\s]/.test(x) || /^[A-Z]/.test(x) ? `'${x}'` : x)).join(', ');
    case 'cubicBezier': return `cubic-bezier(${(v as number[]).join(', ')})`;
    case 'shadow': return (v as { color: string; offsetX: string; offsetY: string; blur: string; spread: string }[]).map((s) => `${s.offsetX} ${s.offsetY} ${s.blur} ${s.spread} ${s.color}`).join(', ');
    default: return String(v);
  }
}
function vars(dok: Gruppe, filter: (sti: string[]) => boolean, innrykk = '  '): string {
  return flat(dok).filter(([s]) => filter(s)).map(([s, t]) => `${innrykk}${navn(s)}: ${cssVerdi(t, dok)};`).join('\n');
}
const fargeVars = (dok: Gruppe, innrykk = '  ') => vars(dok, (s) => s[0] === 'farge' || s[0] === 'skygge', innrykk);
const finn = (id: TemaId, modus: Modus, k: Kontrast) => temaer.find((t) => t.id === id && t.modus === modus && t.k === k)!.dok;

let css = `/* Bivrost – grunnfilen med designtokenene (BIV-GRS-01–06). Generert av grensesnitt/skript/lag-tokens.ts: endre tokenene, ikke denne filen.
 * Fargene for hvert tema ligger i stil/tema/<tema>.css (BIV-B-028). Lenk grunnfilen og temaet brukeren har valgt:
 *   <link rel="stylesheet" href="…/stil/bivrost.css"> <link rel="stylesheet" href="…/stil/tema/marine.css" data-bivrost-tema-fil="aktiv">
 * Uten temafil gir grunnfilen Marine og turkis, lys, normal kontrast.
 * Bruk: <html data-bivrost-tema="marine" data-bivrost-modus="system|lys|mork" data-bivrost-kontrast="system|normal|hoy"
 *        data-bivrost-bredde="begrenset|full" data-bivrost-tetthet="romslig|kompakt" data-bivrost-bevegelse="system|ja|nei">
 * Appene bruker variablene (var(--bivrost-…)), aldri faste farger. Leveres fra appens egen server (ingen CDN).
 * Skriften Gerd Sans er Microsofts Selawik (SIL Open Font License 1.1), se ../fonter/LES-MEG.txt. */

@font-face { font-family: 'Gerd Sans'; src: url('../fonter/gerd-sans-regular.woff2') format('woff2'); font-weight: 400; font-style: normal; font-display: swap; }
@font-face { font-family: 'Gerd Sans'; src: url('../fonter/gerd-sans-semibold.woff2') format('woff2'); font-weight: 600; font-style: normal; font-display: swap; }
@font-face { font-family: 'Gerd Sans'; src: url('../fonter/gerd-sans-bold.woff2') format('woff2'); font-weight: 700; font-style: normal; font-display: swap; }

:root {
${vars(g, () => true)}
  --bivrost-innholdsbredde: var(--bivrost-bredde-begrenset);
  --bivrost-kontrollhoyde: var(--bivrost-tetthet-romslig-kontrollhoyde);
  --bivrost-radhoyde: var(--bivrost-tetthet-romslig-radhoyde);
  --bivrost-celleavstand: var(--bivrost-tetthet-romslig-celleavstand);
  color-scheme: light;
}
:root[data-bivrost-bredde="full"] { --bivrost-innholdsbredde: 100%; }
:root[data-bivrost-tetthet="kompakt"] {
  --bivrost-kontrollhoyde: var(--bivrost-tetthet-kompakt-kontrollhoyde);
  --bivrost-radhoyde: var(--bivrost-tetthet-kompakt-radhoyde);
  --bivrost-celleavstand: var(--bivrost-tetthet-kompakt-celleavstand);
}

/* Standard uten valg: Marine og turkis, lys, normal kontrast. */
:root {
${fargeVars(finn('marine', 'lys', 'normal'))}
}
`;
const temaCss = new Map<TemaId, string>();
// Temafilene (BIV-B-028): selektorene har ikke temaet i seg, så det er filen som er lastet som bestemmer fargene, også
// mens et nytt tema hentes. Alle reglene har samme form, :root + modus + kontrast (spesifisitet 0,3,0), så rekkefølgen
// avgjør: normal kontrast, så valgt høy kontrast, så høy kontrast fra systemet (prefers-contrast) til slutt.
const MODUS_SEL: Record<'lys' | 'mork' | 'system', string> = {
  lys: '[data-bivrost-modus="lys"]',
  mork: '[data-bivrost-modus="mork"]',
  system: ':is([data-bivrost-modus="system"], :not([data-bivrost-modus]))',
};
const KONTRAST_SEL = {
  normal: ':not([data-bivrost-kontrast="hoy"])',
  hoy: '[data-bivrost-kontrast="hoy"]',
  system: ':is([data-bivrost-kontrast="system"], :not([data-bivrost-kontrast]))',
};
const regel = (id: TemaId, m: 'lys' | 'mork' | 'system', k: keyof typeof KONTRAST_SEL, palett: Modus, pk: Kontrast, innrykk = '') =>
  `${innrykk}:root${MODUS_SEL[m]}${KONTRAST_SEL[k]} {\n${innrykk}  color-scheme: ${palett === 'mork' ? 'dark' : 'light'};\n${fargeVars(finn(id, palett, pk), `${innrykk}  `)}\n${innrykk}}\n`;
for (const id of TEMA_REKKEFOLGE) {
  let css = `/* Bivrost – fargene for temaet «${id}» i lys, mørk og høy kontrast (BIV-B-028). Brukes sammen med ../bivrost.css.
 * Generert av grensesnitt/skript/lag-tokens.ts: endre tokenene, ikke denne filen. */
`;
  for (const k of KONTRASTER) {
    const ks = k === 'hoy' ? 'hoy' : 'normal';
    css += `\n/* ${k === 'hoy' ? 'Høy kontrast (valgt)' : 'Normal kontrast'}: lys, mørk og «følg systemet» (B-041). */\n`;
    css += regel(id, 'lys', ks, 'lys', k);
    css += regel(id, 'mork', ks, 'mork', k);
    css += regel(id, 'system', ks, 'lys', k);
    css += `@media (prefers-color-scheme: dark) {\n${regel(id, 'system', ks, 'mork', k, '  ')}}\n`;
  }
  // Høy kontrast fra systemet (prefers-contrast: more) når brukeren har valgt «system» for kontrasten.
  css += `\n/* Høy kontrast fra systemet når kontrasten følger systemet. */\n@media (prefers-contrast: more) {\n`;
  css += regel(id, 'lys', 'system', 'lys', 'hoy', '  ');
  css += regel(id, 'mork', 'system', 'mork', 'hoy', '  ');
  css += regel(id, 'system', 'system', 'lys', 'hoy', '  ');
  css += `}\n@media (prefers-contrast: more) and (prefers-color-scheme: dark) {\n${regel(id, 'system', 'system', 'mork', 'hoy', '  ')}}\n`;
  temaCss.set(id, css);
}
const varigheter = flat(g).filter(([s]) => s[0] === 'bevegelse' && s[1] === 'varighet').map(([s]) => `${navn(s)}: 0ms;`).join(' ');
css += `
/* Redusert bevegelse (WCAG 2.3.3): systemets valg, eller brukerens (data-bivrost-bevegelse="ja"). */
@media (prefers-reduced-motion: reduce) {
  :root:not([data-bivrost-bevegelse="nei"]) { ${varigheter} }
  :root:not([data-bivrost-bevegelse="nei"]) *, :root:not([data-bivrost-bevegelse="nei"]) *::before, :root:not([data-bivrost-bevegelse="nei"]) *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; scroll-behavior: auto !important; }
}
:root[data-bivrost-bevegelse="ja"] { ${varigheter} }
:root[data-bivrost-bevegelse="ja"] *, :root[data-bivrost-bevegelse="ja"] *::before, :root[data-bivrost-bevegelse="ja"] *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; scroll-behavior: auto !important; }

/* Tvungne farger (Windows høy kontrast, PLT-11): systemfargene tar over, også over temafilene (!important). */
@media (forced-colors: active) {
  :root {
    --bivrost-farge-bakgrunn-side: Canvas !important; --bivrost-farge-bakgrunn-flate-1: Canvas !important; --bivrost-farge-bakgrunn-flate-2: Canvas !important; --bivrost-farge-bakgrunn-flate-3: Canvas !important;
    --bivrost-farge-bakgrunn-valgt: Highlight !important; --bivrost-farge-tekst-standard: CanvasText !important; --bivrost-farge-tekst-dempet: CanvasText !important;
    --bivrost-farge-tekst-lenke: LinkText !important; --bivrost-farge-tekst-lenke-besokt: VisitedText !important; --bivrost-farge-tekst-deaktivert: GrayText !important;
    --bivrost-farge-kant-sterk: CanvasText !important; --bivrost-farge-kant-standard: CanvasText !important; --bivrost-farge-kant-fokus: Highlight !important;
    --bivrost-farge-merkevare-bakgrunn: ButtonFace !important; --bivrost-farge-tekst-pa-merkevare: ButtonText !important;
  }
  :focus-visible { outline-color: Highlight; }
}

/* Synlig fokus (WCAG 2.4.7, 2.4.11, 2.4.13). */
:focus-visible { outline: var(--bivrost-fokus-bredde) solid var(--bivrost-farge-kant-fokus); outline-offset: var(--bivrost-fokus-avstand); }

body {
  margin: 0;
  background: var(--bivrost-farge-bakgrunn-side);
  color: var(--bivrost-farge-tekst-standard);
  font-family: var(--bivrost-typografi-familie-standard);
  font-size: var(--bivrost-typografi-storrelse-300);
  line-height: var(--bivrost-typografi-linjehoyde-300);
}
a { color: var(--bivrost-farge-tekst-lenke); }
a:visited { color: var(--bivrost-farge-tekst-lenke-besokt); }
a:hover { color: var(--bivrost-farge-tekst-lenke-hover); }
.bivrost-tall { font-family: var(--bivrost-typografi-familie-tall); font-feature-settings: var(--bivrost-typografi-tall-funksjoner); }
.bivrost-dempet { color: var(--bivrost-farge-tekst-dempet); }
.bivrost-skjult { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.bivrost-feilside main { max-width: var(--bivrost-bredde-lesebredde); margin: var(--bivrost-avstand-xxxl) auto; padding: var(--bivrost-avstand-xxl); background: var(--bivrost-farge-bakgrunn-flate-1); border-radius: var(--bivrost-radius-middels); box-shadow: var(--bivrost-skygge-4); }

/* Utskrift: svart på hvitt (!important, så temafilene ikke vinner), uten skygger og uten det som er merket .bivrost-ikke-utskrift. */
@media print {
  :root { color-scheme: light !important; --bivrost-farge-bakgrunn-side: #FFFFFF !important; --bivrost-farge-bakgrunn-flate-1: #FFFFFF !important; --bivrost-farge-tekst-standard: #000000 !important; --bivrost-farge-tekst-dempet: #333333 !important; --bivrost-farge-tekst-lenke: #000000 !important; }
  * { box-shadow: none !important; }
  .bivrost-ikke-utskrift { display: none !important; }
  a[href^="http"]::after { content: " (" attr(href) ")"; }
}
`;
filer.set('stil/bivrost.css', css);
for (const [id, c] of temaCss) filer.set(`stil/tema/${id}.css`, c);

// ---------------------------------------------------------------- oversikten
const ml = finn('marine', 'lys', 'normal'), mm = finn('marine', 'mork', 'normal');
let md = `# Designtokenene i Bivrost

> Generert av \`grensesnitt/skript/lag-tokens.ts\`. Endre generatoren eller temaene i \`grensesnitt/src/temaer.ts\`, ikke denne filen.

Tokenene følger [W3C Design Tokens Community Group-formatet](https://www.designtokens.org/tr/drafts/format/) (\`$value\`, \`$type\`, alias \`{gruppe.token}\`),
som Style Dictionary v4, Tokens Studio og Figma-verktøy leser direkte (\`tokens/style-dictionary.config.json\`).
Fluent UI v9-temaet lages fra de samme tokenene (\`grensesnitt/src/fluent.ts\`): fargene er Fluents egne navn under \`fluent\`, og grunnlaget har
Fluent-navnet i \`$extensions["no.bivrost"].fluent\`.

**Regel:** appene bruker tokenene (\`var(--bivrost-…)\` i CSS eller Fluent-temaet i React), aldri faste farger. \`skript/sjekk-faste-farger.ts\` finner faste
farger i en app sin kode. Status vises alltid med ikon og tekst i tillegg til farge (WCAG 1.4.1). Diagrammer har alltid en tabellversjon.

**Filer:** \`tokens/grunnlag.tokens.json\` og ${temaer.length} fargefiler \`tokens/tema/<tema>.<modus>.<kontrast>.tokens.json\`
(temaene ${TEMA_REKKEFOLGE.map((t) => `\`${t}\``).join(', ')} × \`lys\`/\`mork\` × \`normal\`/\`hoy\`), og stilfilene: grunnfilen \`stil/bivrost.css\` og én fil per tema, \`stil/tema/<tema>.css\` (BIV-B-028).

**Kontrast:** \`skript/sjekk-kontrast.ts\` kontrollerer alle parene i alle ${temaer.length} settene: tekst minst 4,5:1 (7:1 i høy kontrast),
grafikk, kanter, ikoner og fokus minst 3:1. Den kjører i CI.

## Grunnlaget

| Token | CSS-variabel | Verdi | Fluent |
|---|---|---|---|
`;
for (const [s, t] of flat(g)) {
  const fl = (t.$extensions?.['no.bivrost'] as { fluent?: string | string[] } | undefined)?.fluent;
  md += `| \`${s.join('.')}\` | \`${navn(s)}\` | \`${cssVerdi(t, g).replace(/\|/g, '\\|')}\` | ${fl ? (Array.isArray(fl) ? fl : [fl]).map((x) => `\`${x}\``).join(', ') : ''} |\n`;
}
md += `
## Fargene (semantiske)

Verdiene er for *Marine og turkis* (standardtemaet). De andre temaene har de samme tokenene.

| Token | CSS-variabel | Lys | Mørk | Bruk |
|---|---|---|---|---|
`;
for (const [s, t] of flat(ml).filter(([s]) => s[0] === 'farge')) {
  const mork = flat(mm).find(([x]) => x.join('.') === s.join('.'));
  md += `| \`${s.join('.')}\` | \`${navn(s)}\` | \`${cssVerdi(t, ml)}\` | \`${mork ? cssVerdi(mork[1], mm) : ''}\` | ${t.$description ?? ''} |\n`;
}
md += `
## Valg i brukermenyen og attributtene

| Preferanse (Bivrost) | Attributt på \`<html>\` | Verdier |
|---|---|---|
| \`tema\` | \`data-bivrost-tema\` | ${TEMA_REKKEFOLGE.join(', ')} |
| \`modus\` | \`data-bivrost-modus\` | system (standard), lys, mork |
| \`hoyKontrast\` | \`data-bivrost-kontrast\` | system (standard, følger \`prefers-contrast\`), normal (\`nei\`), hoy (\`ja\`) |
| \`bredde\` | \`data-bivrost-bredde\` | begrenset (standard, 1600 px), full |
| \`tetthet\` | \`data-bivrost-tetthet\` | romslig (standard), kompakt |
| \`redusertBevegelse\` | \`data-bivrost-bevegelse\` | system (standard, følger \`prefers-reduced-motion\`), ja, nei |

\`grensesnitt/src/preferanser.ts\` setter attributtene og husker valget i nettleseren (localStorage med try/catch) til neste sidelasting.
`;
filer.set('../docs/designtokens.md', md);

const sjekk = process.argv.includes('--sjekk');
let ulike = 0;
for (const [rel, innhold] of filer) {
  const fil = join(rot, rel);
  if (sjekk) {
    if (!existsSync(fil) || readFileSync(fil, 'utf8') !== innhold) { console.error(`Ikke i takt: ${rel}`); ulike++; }
  } else {
    mkdirSync(dirname(fil), { recursive: true });
    writeFileSync(fil, innhold);
  }
}
if (sjekk) {
  console.log(ulike ? `\n${ulike} filer er ikke i takt med generatoren. Kjør: npm run tokens` : `Tokenene og stilfilen er i takt (${filer.size} filer).`);
  process.exit(ulike ? 1 : 0);
}
console.log(`Skrev ${filer.size} filer.`);
