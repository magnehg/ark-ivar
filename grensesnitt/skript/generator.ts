// bivrost: fargekilde (generatoren regner ut fargene; appene bruker bare de ferdige tokenene)
// Lager designtokenene (W3C Design Tokens Community Group-formatet, BIV-GRS-01–05) for alle fargetemaene i lys og
// mørk modus, med normal og høy kontrast, og grunnlaget (typografi, avstand, radius, skygge, lag, tetthet, bredde,
// brytepunkter, ikoner, bevegelse og fokus). Fluent UI v9-fargene lages med Fluents egen createLightTheme /
// createDarkTheme fra skalaen, så Fluent-komponentene og Bivrosts egne sider får de samme fargene.
import { createDarkTheme, createLightTheme } from '@fluentui/tokens';
import { ARCHIMATE, DIVERGERENDE, KATEGORISK, STATUS, TEMAER, type ArchiMateLag, type Kontrast, type Modus, type Nokkelfarger, type TemaId } from '../src/temaer.ts';
import { bestAv, blandHex, blandLab, fraOklab, juster, kontrast, tilOklab, type Lab } from '../src/farger.ts';

export type Token = { $type?: string; $value: unknown; $description?: string; $extensions?: Record<string, unknown> };
export type Gruppe = { [k: string]: Token | Gruppe | string | Record<string, unknown> | undefined; $description?: string };
type Trinn = 10 | 20 | 30 | 40 | 50 | 60 | 70 | 80 | 90 | 100 | 110 | 120 | 130 | 140 | 150 | 160;
type Rampe = Record<Trinn, string>;

/** Fargeskala i 16 trinn (Fluent BrandVariants): 10 nesten svart i hovedfargens tone, 80 hovedfarge, 110 aksent, 160 lys. */
export function lagRampe(f: Nokkelfarger): Rampe {
  const h = tilOklab(f.hoved), a = tilOklab(f.aksent), l = tilOklab(f.lys);
  const mork: Lab = [0.17, h[1] * 0.55, h[2] * 0.55];
  const ankere: [number, Lab][] = [[10, mork], [80, h], [110, a], [160, l]];
  const r = {} as Rampe;
  for (let t = 10; t <= 160; t += 10) {
    const i = ankere.findIndex(([n], j) => t >= n && (j === ankere.length - 1 || t <= ankere[j + 1][0]));
    const [n0, c0] = ankere[Math.min(i, ankere.length - 2)], [n1, c1] = ankere[Math.min(i + 1, ankere.length - 1)];
    r[t as Trinn] = t === 80 ? f.hoved : t === 110 ? f.aksent : t === 160 ? f.lys : fraOklab(blandLab(c0, c1, (t - n0) / (n1 - n0)));
  }
  return r;
}

const MORK_KORT = '#292929';

/** Mørk modus (B-041): aksenten tar over for knapper, lenker, faner og avkrysninger, med mørk tekst på knappene. */
function morkeOverstyringer(r: Rampe): Record<string, string> {
  return {
    colorBrandBackground: r[110], colorBrandBackgroundHover: r[120], colorBrandBackgroundPressed: r[100], colorBrandBackgroundSelected: r[100],
    colorNeutralForegroundOnBrand: r[10],
    colorCompoundBrandBackground: r[110], colorCompoundBrandBackgroundHover: r[120], colorCompoundBrandBackgroundPressed: r[100],
    colorCompoundBrandForeground1: r[110], colorCompoundBrandForeground1Hover: r[120], colorCompoundBrandForeground1Pressed: r[100],
    colorCompoundBrandStroke: r[110], colorCompoundBrandStrokeHover: r[120], colorCompoundBrandStrokePressed: r[100],
    colorBrandForeground1: r[110], colorBrandForeground2: r[120], colorBrandForeground2Hover: r[130], colorBrandForeground2Pressed: r[140],
    colorBrandForegroundLink: r[110], colorBrandForegroundLinkHover: r[120], colorBrandForegroundLinkPressed: r[100], colorBrandForegroundLinkSelected: r[110],
    colorNeutralForeground2BrandHover: r[110], colorNeutralForeground2BrandPressed: r[100], colorNeutralForeground2BrandSelected: r[110],
    colorNeutralForeground3BrandHover: r[110], colorNeutralForeground3BrandPressed: r[100], colorNeutralForeground3BrandSelected: r[110],
    colorNeutralStrokeAccessibleSelected: r[110], colorBrandStroke1: r[110],
    colorBrandBackground2: blandHex(r[110], MORK_KORT, 0.2), colorBrandBackground2Hover: blandHex(r[110], MORK_KORT, 0.15), colorBrandBackground2Pressed: blandHex(r[110], MORK_KORT, 0.11),
    colorBrandStroke2: blandHex(r[110], MORK_KORT, 0.4), colorBrandStroke2Hover: blandHex(r[110], MORK_KORT, 0.4), colorBrandStroke2Pressed: blandHex(r[110], MORK_KORT, 0.5),
    colorBrandStroke2Contrast: blandHex(r[110], MORK_KORT, 0.4),
  };
}

/**
 * Høy kontrast (BIV-GRS-04): rene bakgrunner, tekst i svart eller hvitt, sterke kanter, og merkevarefarger som gir
 * minst 7:1 (WCAG 2.2 AAA 1.4.6) for tekst. Brukes når brukeren velger høy kontrast; Windows-modusen med tvungne
 * farger (forced-colors) håndteres i stilfilen med systemfargene.
 */
function hoyKontrast(t: Record<string, string>, modus: Modus): Record<string, string> {
  const bg = modus === 'lys' ? '#FFFFFF' : '#000000';
  const fg = modus === 'lys' ? '#000000' : '#FFFFFF';
  const fg2 = modus === 'lys' ? '#1A1A1A' : '#F0F0F0';
  const merke = juster(t.colorBrandBackground, [bg], 7);
  const paMerke = bestAv(merke);
  const valgt = blandHex(merke, bg, modus === 'lys' ? 0.1 : 0.25);
  const hover = blandHex(fg, bg, 0.08);
  // Tekst og lenker i merkevarefargen: minst 7:1 også på valgt rad og med pekeren over.
  const merkeTekst = juster(merke, [bg, valgt, hover], 7);
  const ut: Record<string, string> = {};
  for (const k of Object.keys(t)) {
    if (/^colorNeutralBackground[1-6](Hover|Pressed|Selected)?$/.test(k) || k === 'colorSubtleBackground' || k === 'colorNeutralBackgroundStatic') ut[k] = bg;
  }
  for (const k of ['colorNeutralBackground1Hover', 'colorNeutralBackground2Hover', 'colorNeutralBackground3Hover', 'colorSubtleBackgroundHover']) ut[k] = blandHex(fg, bg, 0.08);
  for (const k of ['colorNeutralBackground1Pressed', 'colorNeutralBackground2Pressed', 'colorNeutralBackground3Pressed', 'colorSubtleBackgroundPressed']) ut[k] = blandHex(fg, bg, 0.14);
  Object.assign(ut, {
    colorNeutralForeground1: fg, colorNeutralForeground2: fg2, colorNeutralForeground3: fg2, colorNeutralForeground4: fg2,
    colorNeutralStroke1: fg2, colorNeutralStroke2: blandHex(fg, bg, 0.6), colorNeutralStrokeAccessible: fg, colorStrokeFocus2: fg,
    colorBrandBackground: merke, colorBrandBackgroundHover: juster(blandHex(merke, fg, 0.85), [bg], 7), colorBrandBackgroundPressed: juster(blandHex(merke, fg, 0.7), [bg], 7),
    colorBrandBackgroundSelected: merke, colorNeutralForegroundOnBrand: paMerke,
    colorCompoundBrandBackground: merke, colorCompoundBrandStroke: merke, colorCompoundBrandForeground1: merke,
    colorBrandForeground1: merkeTekst, colorBrandForeground2: merkeTekst, colorBrandForegroundLink: merkeTekst, colorBrandForegroundLinkHover: merkeTekst,
    colorBrandForegroundLinkPressed: merkeTekst, colorBrandForegroundLinkSelected: merkeTekst, colorBrandStroke1: merkeTekst,
    colorPaletteYellowBackground2: juster(t.colorPaletteYellowBackground2 ?? '#FFF9C4', [fg], 7),
    colorBrandBackground2: valgt, colorBrandBackground2Hover: valgt, colorBrandBackground2Pressed: valgt,
    colorNeutralForegroundInverted: bg,
  });
  return ut;
}

/** Alle Fluent UI v9-fargene for et tema (navnene er Fluents). */
export function fluentFarger(id: TemaId, modus: Modus, k: Kontrast): Record<string, string> {
  const r = lagRampe(TEMAER[id]);
  const base = (modus === 'mork' ? { ...createDarkTheme(r), ...morkeOverstyringer(r) } : createLightTheme(r)) as unknown as Record<string, unknown>;
  const farger: Record<string, string> = {};
  for (const [n, v] of Object.entries(base)) {
    if (typeof v === 'string' && n.startsWith('color') && /^#[0-9a-fA-F]{6}$/.test(v)) farger[n] = v.toUpperCase();
  }
  return k === 'hoy' ? { ...farger, ...hoyKontrast(farger, modus) } : farger;
}

const farge = (v: string, beskrivelse?: string): Token => ({ $type: 'color', $value: v, ...(beskrivelse ? { $description: beskrivelse } : {}) });
const alias = (fluentNavn: string, beskrivelse?: string): Token => ({ $type: 'color', $value: `{fluent.${fluentNavn}}`, ...(beskrivelse ? { $description: beskrivelse } : {}) });

/** Toppfeltet og logoen (Gerðs og appenes skall, B-041). */
function toppfelt(f: Nokkelfarger, r: Rampe, modus: Modus, k: Kontrast): Record<string, string> {
  if (k === 'hoy') {
    const bg = modus === 'lys' ? juster(f.hoved, ['#FFFFFF'], 7) : '#000000';
    return { bakgrunn: bg, tekst: '#FFFFFF', kant: modus === 'lys' ? bg : '#FFFFFF', 'logo-fyll': juster(f.aksent, [bg], 4.5), 'logo-ramme': '#FFFFFF' };
  }
  return modus === 'mork'
    ? { bakgrunn: r[20], tekst: '#FFFFFF', kant: r[40], 'logo-fyll': f.aksent, 'logo-ramme': '#FFFFFF' }
    : { bakgrunn: f.hoved, tekst: '#FFFFFF', kant: f.hoved, 'logo-fyll': f.aksent, 'logo-ramme': '#FFFFFF' };
}

/** Fargetokenene for ett tema, én modus og ett kontrastnivå. */
export function temaTokens(id: TemaId, modus: Modus, k: Kontrast): Gruppe {
  const f = TEMAER[id];
  const r = lagRampe(f);
  const fl = fluentFarger(id, modus, k);
  const tekstKrav = k === 'hoy' ? 7 : 4.5;
  const flater = [fl.colorNeutralBackground1, fl.colorNeutralBackground2, fl.colorNeutralBackground3];
  const lys = modus === 'lys';
  const status: Gruppe = {};
  for (const [s, base] of Object.entries(STATUS)) {
    const bakgrunn = k === 'hoy' ? fl.colorNeutralBackground1 : blandHex(base, fl.colorNeutralBackground1, lys ? 0.1 : 0.22);
    status[s] = {
      bakgrunn: farge(bakgrunn),
      tekst: farge(juster(base, [bakgrunn, ...flater], tekstKrav)),
      kant: farge(juster(base, [bakgrunn, ...flater], 3)),
      ikon: farge(juster(base, [bakgrunn, ...flater], k === 'hoy' ? 4.5 : 3)),
    };
  }
  const kategorisk: Gruppe = {};
  KATEGORISK.forEach((c, i) => { kategorisk[String(i + 1)] = farge(juster(c, flater, 3)); });
  const sekv: Gruppe = {}, sekvTekst: Gruppe = {};
  const fra = tilOklab(lys ? blandHex(f.hoved, '#FFFFFF', 0.08) : blandHex(f.aksent, '#1F1F1F', 0.12));
  const til = tilOklab(lys ? f.hoved : f.aksent);
  for (let i = 0; i < 7; i++) {
    const c = fraOklab(blandLab(fra, til, i / 6));
    const sterkest = i === 6 ? juster(c, flater, 3) : c;
    sekv[String(i + 1)] = farge(sterkest);
    sekvTekst[String(i + 1)] = farge(bestAv(sterkest));
  }
  const div: Gruppe = {}, divTekst: Gruppe = {};
  const midt = lys ? DIVERGERENDE.midt : '#3A3A3A';
  const lav = juster(DIVERGERENDE.lav, flater, 3), hoy = juster(DIVERGERENDE.hoy, flater, 3);
  for (let i = 0; i < 9; i++) {
    const c = i < 4 ? fraOklab(blandLab(tilOklab(lav), tilOklab(midt), i / 4)) : fraOklab(blandLab(tilOklab(midt), tilOklab(hoy), (i - 4) / 4));
    div[String(i + 1)] = farge(c);
    divTekst[String(i + 1)] = farge(bestAv(c));
  }
  const archimate: Gruppe = {};
  for (const [lag, base] of Object.entries(ARCHIMATE) as [ArchiMateLag, string][]) {
    const fyll = k === 'hoy' ? fl.colorNeutralBackground1 : lys ? base : blandHex(base, MORK_KORT, 0.22);
    const tekst = bestAv(fyll, [fl.colorNeutralForeground1, lys ? '#000000' : '#FFFFFF']);
    const kantBase = lys ? fraOklab([tilOklab(base)[0] * 0.55, tilOklab(base)[1] * 1.4, tilOklab(base)[2] * 1.4]) : base;
    archimate[lag] = {
      fyll: farge(fyll),
      tekst: farge(tekst),
      kant: farge(juster(kantBase, [fyll, ...flater], 3)),
    };
  }
  const besokt = juster(lys ? '#5C2E91' : '#C8A2F0', [...flater, fl.colorBrandBackground2], tekstKrav);
  const skyggeAlfa = lys ? ['1F', '24'] : ['3D', '47'];
  const skygge: Gruppe = {};
  for (const n of [2, 4, 8, 16, 28, 64]) {
    skygge[String(n)] = { $type: 'shadow', $value: [
      { color: `#000000${skyggeAlfa[0]}`, offsetX: '0px', offsetY: '0px', blur: n > 8 ? '8px' : '2px', spread: '0px' },
      { color: `#000000${skyggeAlfa[1]}`, offsetX: '0px', offsetY: `${n / 2}px`, blur: `${n}px`, spread: '0px' },
    ] };
  }
  return {
    $description: `Fargetema «${id}», ${lys ? 'lys' : 'mørk'} modus, ${k === 'hoy' ? 'høy' : 'normal'} kontrast. Generert av grensesnitt/skript/lag-tokens.ts – endre ikke for hånd.`,
    $extensions: { 'no.bivrost': { tema: id, modus, kontrast: k, nokkelfarger: f } },
    farge: {
      bakgrunn: {
        side: alias('colorNeutralBackground3', 'Bakgrunnen bak innholdet'),
        'flate-1': alias('colorNeutralBackground1', 'Kort, tabeller, dialoger'),
        'flate-2': alias('colorNeutralBackground2', 'Sidemeny og paneler'),
        'flate-3': alias('colorNeutralBackground4', 'Nivå tre (felt i panel)'),
        hover: alias('colorNeutralBackground1Hover'),
        trykket: alias('colorNeutralBackground1Pressed'),
        valgt: alias('colorBrandBackground2', 'Valgt rad, menypunkt og periode'),
        'valgt-hover': alias('colorBrandBackground2Hover'),
        deaktivert: alias('colorNeutralBackgroundDisabled'),
        overlegg: farge(lys ? '#00000066' : '#000000A3', 'Bak dialoger'),
      },
      tekst: {
        standard: alias('colorNeutralForeground1'),
        dempet: alias('colorNeutralForeground2', 'Sekundærtekst (minst 4,5:1)'),
        deaktivert: alias('colorNeutralForegroundDisabled', 'Deaktivert (unntatt fra kontrastkravet, WCAG 1.4.3)'),
        'pa-merkevare': alias('colorNeutralForegroundOnBrand'),
        merkevare: alias('colorBrandForeground1'),
        lenke: alias('colorBrandForegroundLink'),
        'lenke-hover': alias('colorBrandForegroundLinkHover'),
        'lenke-besokt': farge(besokt, 'Besøkt lenke'),
      },
      kant: {
        svak: alias('colorNeutralStroke2', 'Dekorative skillelinjer (ikke grenser for kontroller)'),
        standard: alias('colorNeutralStroke1'),
        sterk: alias('colorNeutralStrokeAccessible', 'Grenser for kontroller (minst 3:1, WCAG 1.4.11)'),
        fokus: alias('colorStrokeFocus2', 'Fokusringen (minst 3:1, WCAG 2.4.11/2.4.13)'),
        valgt: alias('colorBrandStroke1'),
      },
      merkevare: {
        bakgrunn: alias('colorBrandBackground'),
        'bakgrunn-hover': alias('colorBrandBackgroundHover'),
        'bakgrunn-trykket': alias('colorBrandBackgroundPressed'),
        'bakgrunn-valgt': alias('colorBrandBackgroundSelected'),
        hoved: farge(f.hoved, 'Temaets hovedfarge (nøkkelfarge)'),
        aksent: farge(f.aksent, 'Temaets aksent (nøkkelfarge)'),
        lys: farge(f.lys, 'Temaets lyse bakgrunn (nøkkelfarge)'),
      },
      toppfelt: Object.fromEntries(Object.entries(toppfelt(f, r, modus, k)).map(([n, v]) => [n, farge(v)])),
      status,
      data: {
        $description: 'Paletter for diagrammer. Kategoriene er Okabe–Ito, justert til minst 3:1 mot flatene. Bruk alltid tekst eller mønster i tillegg, og en tabellversjon av diagrammet.',
        kategorisk,
        sekvensiell: sekv,
        'tekst-pa-sekvensiell': sekvTekst,
        divergerende: div,
        'tekst-pa-divergerende': divTekst,
      },
      archimate,
    },
    skygge,
    fluent: Object.fromEntries(Object.entries(fl).sort(([a], [b]) => a.localeCompare(b)).map(([n, v]) => [n, farge(v)])),
  };
}

const dim = (px: number, fluent?: string): Token => ({ $type: 'dimension', $value: `${px}px`, ...(fluent ? { $extensions: { 'no.bivrost': { fluent } } } : {}) });
const tall = (v: number, fluent?: string): Token => ({ $type: 'number', $value: v, ...(fluent ? { $extensions: { 'no.bivrost': { fluent } } } : {}) });
const tid = (ms: number, fluent?: string): Token => ({ $type: 'duration', $value: `${ms}ms`, ...(fluent ? { $extensions: { 'no.bivrost': { fluent } } } : {}) });

/** Grunnlaget: alt som ikke er farge og er likt i alle temaene. */
export function grunnlag(): Gruppe {
  const storrelser: [string, number, string][] = [['100', 10, 'fontSizeBase100'], ['200', 12, 'fontSizeBase200'], ['300', 14, 'fontSizeBase300'], ['400', 16, 'fontSizeBase400'],
    ['500', 20, 'fontSizeBase500'], ['600', 24, 'fontSizeBase600'], ['700', 28, 'fontSizeHero700'], ['800', 32, 'fontSizeHero800'], ['900', 40, 'fontSizeHero900'], ['1000', 68, 'fontSizeHero1000']];
  const linjer: [string, number, string][] = [['100', 14, 'lineHeightBase100'], ['200', 16, 'lineHeightBase200'], ['300', 20, 'lineHeightBase300'], ['400', 22, 'lineHeightBase400'],
    ['500', 28, 'lineHeightBase500'], ['600', 32, 'lineHeightBase600'], ['700', 36, 'lineHeightHero700'], ['800', 40, 'lineHeightHero800'], ['900', 52, 'lineHeightHero900'], ['1000', 92, 'lineHeightHero1000']];
  const avstand: [string, number, string][] = [['ingen', 0, 'None'], ['xxs', 2, 'XXS'], ['xs', 4, 'XS'], ['sxs', 6, 'SNudge'], ['s', 8, 'S'], ['ms', 10, 'MNudge'],
    ['m', 12, 'M'], ['l', 16, 'L'], ['xl', 20, 'XL'], ['xxl', 24, 'XXL'], ['xxxl', 32, 'XXXL']];
  return {
    $description: 'Grunnlaget i Bivrosts designpakke (likt i alle temaene). Generert av grensesnitt/skript/lag-tokens.ts – endre ikke for hånd.',
    typografi: {
      familie: {
        standard: { $type: 'fontFamily', $value: ['Segoe UI', 'Gerd Sans', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Helvetica Neue', 'sans-serif'],
          $description: 'Segoe UI der den finnes (Windows), ellers Gerd Sans (Microsofts Selawik) fra egen server (B-042, PLT-08/09)', $extensions: { 'no.bivrost': { fluent: 'fontFamilyBase' } } },
        tall: { $type: 'fontFamily', $value: ['Segoe UI', 'Gerd Sans', 'sans-serif'], $description: 'Til tabeller og tall: bruk med typografi.tall.funksjoner (tabellsifre)' },
        kode: { $type: 'fontFamily', $value: ['Consolas', 'Courier New', 'monospace'], $extensions: { 'no.bivrost': { fluent: 'fontFamilyMonospace' } } },
      },
      tall: { funksjoner: { $type: 'string', $value: '"tnum" 1, "lnum" 1', $description: 'font-feature-settings for tall i tabeller (like brede sifre)' } },
      storrelse: Object.fromEntries(storrelser.map(([n, px, fl]) => [n, dim(px, fl)])),
      linjehoyde: Object.fromEntries(linjer.map(([n, px, fl]) => [n, dim(px, fl)])),
      vekt: { vanlig: { $type: 'fontWeight', $value: 400, $extensions: { 'no.bivrost': { fluent: 'fontWeightRegular' } } },
        halvfet: { $type: 'fontWeight', $value: 600, $extensions: { 'no.bivrost': { fluent: 'fontWeightSemibold' } } },
        fet: { $type: 'fontWeight', $value: 700, $extensions: { 'no.bivrost': { fluent: 'fontWeightBold' } } } },
    },
    avstand: Object.fromEntries(avstand.map(([n, px, fl]) => [n, { ...dim(px), $extensions: { 'no.bivrost': { fluent: [`spacingHorizontal${fl}`, `spacingVertical${fl}`] } } }])),
    radius: { ingen: dim(0, 'borderRadiusNone'), liten: dim(2, 'borderRadiusSmall'), middels: dim(4, 'borderRadiusMedium'), stor: dim(6, 'borderRadiusLarge'),
      xstor: dim(8, 'borderRadiusXLarge'), sirkel: dim(10000, 'borderRadiusCircular') },
    kantbredde: { tynn: dim(1, 'strokeWidthThin'), tykk: dim(2, 'strokeWidthThick'), tykkere: dim(3, 'strokeWidthThicker'), tykkest: dim(4, 'strokeWidthThickest') },
    lag: { $description: 'Lag (z-index), fra bunnen', innhold: tall(0), sidemeny: tall(100), toppfelt: tall(200), nedtrekk: tall(1000), dialog: tall(2000), varsel: tall(3000), verktoytips: tall(4000) },
    tetthet: {
      romslig: { kontrollhoyde: dim(32), radhoyde: dim(44), celleavstand: dim(12), $description: 'Standard' },
      kompakt: { kontrollhoyde: dim(24), radhoyde: dim(32), celleavstand: dim(6), $description: 'Mer på skjermen (valgt av brukeren)' },
    },
    bredde: {
      begrenset: { $type: 'dimension', $value: '1600px', $description: 'Rammen med sidemeny og innhold er høyst 1600 px og står midt på skjermen (B-049)' },
      sidemeny: dim(240),
      lesebredde: { $type: 'dimension', $value: '72ch', $description: 'Høyst omtrent 72 tegn per linje i løpende tekst' },
    },
    brytepunkt: { smal: dim(480), middels: dim(768), bred: dim(1024), stor: dim(1366), svaer: dim(1920) },
    ikon: { liten: dim(16), standard: dim(20), stor: dim(24), xstor: dim(32), hel: dim(48) },
    bevegelse: {
      varighet: { ultrarask: tid(50, 'durationUltraFast'), raskere: tid(100, 'durationFaster'), rask: tid(150, 'durationFast'), normal: tid(200, 'durationNormal'),
        rolig: tid(250, 'durationGentle'), langsom: tid(300, 'durationSlow'), langsommere: tid(400, 'durationSlower'), ultralangsom: tid(500, 'durationUltraSlow') },
      kurve: {
        standard: { $type: 'cubicBezier', $value: [0.33, 0, 0.67, 1], $extensions: { 'no.bivrost': { fluent: 'curveEasyEase' } } },
        inn: { $type: 'cubicBezier', $value: [0.9, 0.1, 1, 0.2], $extensions: { 'no.bivrost': { fluent: 'curveAccelerateMid' } } },
        ut: { $type: 'cubicBezier', $value: [0.1, 0.9, 0.2, 1], $extensions: { 'no.bivrost': { fluent: 'curveDecelerateMid' } } },
        lineaer: { $type: 'cubicBezier', $value: [0, 0, 1, 1], $extensions: { 'no.bivrost': { fluent: 'curveLinear' } } },
      },
      $description: 'Med redusert bevegelse (prefers-reduced-motion eller brukerens valg) settes alle varighetene til 0 i stilfilen.',
    },
    fokus: {
      bredde: { ...dim(2), $description: 'Minst 2 px rundt hele kontrollen (WCAG 2.4.13)' },
      avstand: dim(2),
    },
  };
}

/** Løser opp {a.b.c}-alias mot dokumentet. */
export function slaaOpp(dok: Gruppe, verdi: unknown, dybde = 0): unknown {
  if (typeof verdi !== 'string' || !/^\{[^}]+\}$/.test(verdi)) return verdi;
  if (dybde > 10) throw new Error(`Alias i ring: ${verdi}`);
  let x: unknown = dok;
  for (const del of verdi.slice(1, -1).split('.')) x = (x as Record<string, unknown> | undefined)?.[del];
  if (x === undefined || typeof x !== 'object' || x === null || !('$value' in x)) throw new Error(`Ukjent alias: ${verdi}`);
  return slaaOpp(dok, (x as Token).$value, dybde + 1);
}

/** Alle tokenene i et dokument som [sti, token], uten grupper og metadata. */
export function flat(g: Gruppe, sti: string[] = []): [string[], Token][] {
  const ut: [string[], Token][] = [];
  for (const [k, v] of Object.entries(g)) {
    if (k.startsWith('$') || v === undefined || typeof v !== 'object') continue;
    if ('$value' in v) ut.push([[...sti, k], v as Token]);
    else ut.push(...flat(v as Gruppe, [...sti, k]));
  }
  return ut;
}

export { kontrast };
