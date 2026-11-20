// Tester for designpakken: fargeregningen, tokenene, Fluent-temaet, preferansene og språkhjelperne.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { blandHex, juster, kontrast, luminans, tilOklab, fraOklab } from '../src/farger.ts';
import { fluentTema, tokenfil } from '../src/fluent.ts';
import { bruk, byttTemaFil, husk, les, rens, tema, temaFil, type Lenke } from '../src/preferanser.ts';
import { slaSammen, sprakFraSti, velgSprak } from '../src/i18n.ts';
import { TEMAER } from '../src/temaer.ts';
import { flat, grunnlag, slaaOpp, temaTokens } from '../skript/generator.ts';

test('kontrast etter WCAG 2.2', () => {
  assert.equal(kontrast('#000000', '#FFFFFF').toFixed(1), '21.0');
  assert.equal(kontrast('#777777', '#FFFFFF').toFixed(2), '4.48');
  assert.equal(luminans('#FFFFFF'), 1);
  assert.equal(blandHex('#000000', '#FFFFFF', 0.5), '#808080');
  assert.equal(fraOklab(tilOklab('#336699')), '#336699');
  const j = juster('#FFCC66', ['#FFFFFF'], 4.5);
  assert.ok(kontrast(j, '#FFFFFF') >= 4.5);
  assert.equal(juster('#000000', ['#FFFFFF'], 4.5), '#000000', 'en farge som holder kravet, endres ikke');
});

test('alle fargefilene har de samme tokenene', () => {
  const navn = (d: Record<string, unknown>) => flat(d as never).map(([s]) => s.join('.')).filter((s) => !s.startsWith('fluent.')).sort();
  const forste = navn(temaTokens('marine', 'lys', 'normal'));
  for (const f of readdirSync(new URL('../tokens/tema/', import.meta.url))) {
    const d = JSON.parse(readFileSync(new URL(`../tokens/tema/${f}`, import.meta.url), 'utf8'));
    assert.deepEqual(navn(d), forste, f);
    for (const [s, t] of flat(d)) if (t.$type === 'color') assert.match(String(slaaOpp(d, t.$value)), /^#[0-9A-F]{6}([0-9A-F]{2})?$/, `${f} ${s.join('.')}`);
  }
  assert.equal(readdirSync(new URL('../tokens/tema/', import.meta.url)).length, 20);
});

test('Fluent-temaet fra tokenene', () => {
  const t = fluentTema(grunnlag() as never, temaTokens('vinrod', 'mork', 'normal') as never);
  assert.equal(t.fontSizeBase300, '14px');
  assert.equal(t.spacingHorizontalM, '12px');
  assert.equal(t.spacingVerticalM, '12px');
  assert.equal(t.fontWeightSemibold, 600);
  assert.equal(t.curveEasyEase, 'cubic-bezier(0.33,0,0.67,1)');
  assert.match(String(t.fontFamilyBase), /^'Segoe UI', 'Gerd Sans'/);
  assert.match(String(t.colorBrandBackground), /^#[0-9A-F]{6}$/);
  assert.equal(t.colorBrandBackground, '#FF9999', 'mørk modus: aksenten på knappene (B-041)');
  assert.equal(tokenfil('marine', 'lys', 'hoy'), 'marine.lys.hoy.tokens.json');
  assert.throws(() => tokenfil('../x', 'lys', 'normal'));
});

test('preferansene i nettleseren tåler lagring som kaster', () => {
  const feil = { getItem: () => { throw new Error('blokkert'); }, setItem: () => { throw new Error('blokkert'); } };
  assert.deepEqual(les(feil), {});
  assert.equal(husk(feil, { tema: 'mose' }), false);
  assert.deepEqual(les(undefined), {});
  const m = new Map<string, string>();
  const lager = { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => { m.set(k, v); } };
  assert.equal(husk(lager, { tema: 'mose', modus: 'mork', bredde: 'full' }), true);
  assert.deepEqual(les(lager), { tema: 'mose', modus: 'mork', bredde: 'full' });
  m.set('bivrost:utseende', '{"tema":"<script>","modus":"rosa","bredde":"full"}');
  assert.deepEqual(les(lager), { bredde: 'full' }, 'ugyldige verdier forkastes');
  assert.deepEqual(rens('x'), {});
  const attr = new Map<string, string>();
  bruk({ setAttribute: (n, v) => attr.set(n, v), removeAttribute: (n) => attr.delete(n) }, { tema: 'teal', hoyKontrast: 'ja', preferredLanguage: 'nn' });
  assert.equal(attr.get('data-bivrost-tema'), 'teal');
  assert.equal(attr.get('data-bivrost-kontrast'), 'hoy');
  assert.equal(attr.get('data-bivrost-modus'), 'system');
  assert.equal(attr.get('lang'), 'nn');
  // Temaets stilfil (BIV-B-028): den nye hentes ved siden av den gamle, som fjernes først når den nye er lastet.
  type FalskLenke = Lenke & { attr: Map<string, string>; hendelser: Map<string, () => void> };
  const liste: FalskLenke[] = [];
  const lag = (attr: Map<string, string>): FalskLenke => {
    const l: FalskLenke = {
      attr, hendelser: new Map(),
      getAttribute: (n) => l.attr.get(n) ?? null, setAttribute: (n, v) => { l.attr.set(n, v); },
      cloneNode: () => lag(new Map(l.attr)),
      after: (n) => { liste.splice(liste.indexOf(l) + 1, 0, n as FalskLenke); },
      remove: () => { const i = liste.indexOf(l); if (i >= 0) liste.splice(i, 1); },
      addEventListener: (t, f) => { l.hendelser.set(t, f); },
    };
    return l;
  };
  liste.push(lag(new Map([['data-bivrost-tema-fil', 'aktiv'], ['href', '/bivrost/ressurser/stil/tema/marine.css']])));
  const dok = { querySelectorAll: () => liste.filter((l) => l.attr.has('data-bivrost-tema-fil')) };
  const href = () => liste.map((l) => `${l.attr.get('data-bivrost-tema-fil')}:${l.attr.get('href')?.split('/').pop()}`);
  const e = { setAttribute: () => {}, removeAttribute: () => {} };
  bruk(e, { tema: 'marine' }, dok);
  assert.deepEqual(href(), ['aktiv:marine.css'], 'samme tema: ingen ny henting');
  bruk(e, { tema: 'mose' }, dok);
  assert.deepEqual(href(), ['aktiv:marine.css', 'venter:mose.css'], 'den gamle står til den nye er lastet');
  liste[1].hendelser.get('load')!();
  assert.deepEqual(href(), ['aktiv:mose.css']);
  bruk(e, { tema: 'teal' }, dok);
  liste[1].hendelser.get('error')!();
  assert.deepEqual(href(), ['aktiv:mose.css'], 'feiler hentingen, beholdes den gamle');
  bruk(e, { tema: 'teal' }, dok);
  bruk(e, { tema: 'vinrod' }, dok);
  assert.deepEqual(href(), ['aktiv:mose.css', 'venter:vinrod.css'], 'et bytte som ikke er ferdig, avbrytes av et nytt');
  byttTemaFil(dok, '/bivrost/ressurser/stil/tema/mose.css');
  assert.deepEqual(href(), ['aktiv:mose.css'], 'tilbake til samme fil som den aktive: det ventende byttet avbrytes');
  assert.equal(temaFil({ tema: 'finnesikke' }, '/a'), '/a/stil/tema/marine.css');
  assert.equal(tema({ tema: '<x>' }), 'marine');
  for (const id of Object.keys(TEMAER)) {
    assert.ok(existsSync(new URL(`../stil/tema/${id}.css`, import.meta.url)), `stil/tema/${id}.css finnes`);
  }
});

test('språk: filene slås sammen med hovedspråket under, og valget følger rekkefølgen', () => {
  assert.equal(sprakFraSti('../kjerne/i18n/nn/bivrost-kjerne.json'), 'nn');
  assert.equal(sprakFraSti('../kjerne/i18n/se/bivrost-kjerne.json'), 'se');
  const r = slaSammen({
    'x/i18n/nb/a.json': { default: { 'bivrost.a': 'A', 'bivrost.b': 'B' } },
    'x/i18n/nn/a.json': { default: { 'bivrost.a': 'A (nn)' } },
  }, 'nn');
  assert.deepEqual(r.meldinger, { 'bivrost.a': 'A (nn)', 'bivrost.b': 'B' });
  assert.deepEqual([...r.fraHovedsprak], ['bivrost.b']);
  assert.equal(velgSprak(['nb', 'nn', 'en'], null, 'en-GB,nb;q=0.8'), 'en');
  assert.equal(velgSprak(['nb', 'nn', 'en'], 'nn', 'en'), 'nn');
  assert.equal(velgSprak(['nb', 'nn', 'en'], undefined, 'no'), 'nb');
  assert.equal(velgSprak(['nb', 'nn', 'en'], 'de', 'fr'), 'nb');
});
