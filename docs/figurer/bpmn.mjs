/**
 * Tegner prosessdiagrammer etter BPMN 2.0 som SVG.
 *
 * Prosessene i arkivplanen handler om hvem som gjør hva. BPMN viser det med et basseng (virksomheten) delt i baner
 * (rollene), og med faste symboler: hendelser som sirkler, oppgaver som avrundede rektangler, eksklusive porter som
 * ruter med kryss, sekvensflyt som hel linje og meldingsflyt som stiplet linje til og fra andre deltakere. Mermaid,
 * som de andre figurene i dokumentasjonen bruker, har verken basseng eller baner. Derfor tegnes prosessene her.
 *
 * Elementene plasseres i et rutenett (bane, kolonne og rad i banen), og flyten legges rettvinklet rundt elementene
 * med et korteste vei-søk. Fargene er tokenene i designpakken (grensesnitt/), brukt som CSS-variabler med
 * Marine-verdiene som reserve, så figuren følger temaet når den står på en side som laster designpakken.
 *
 * Modulen har ingen avhengigheter. Beskrivelsene av figurene står i figurer.mjs, og lag-figurer.mjs skriver filene.
 */

const R = 8;            // Rutenettet flyten følger. Alle mål er delelige med R, så rette linjer treffer portene.
const KOL = 176;        // Avstanden mellom to kolonner.
const RAD = 128;        // Høyden på én rad i en bane.
const MARG = 16;        // Luft rundt hele figuren.
const HODE = 32;        // Bredden på feltet med navnet på bassenget og på banen.
const EKSTERN = 48;     // Høyden på et sammenslått basseng for en ekstern deltaker.
const LUFT = 48;        // Avstanden mellom bassengene: plass til meldingsflyten.
const LINJE = 15;       // Linjeavstanden i teksten.
const TEGN = 6.4;       // Omtrentlig bredde på ett tegn, brukt til å sette av plass til etiketter.

/** Elementene: bredde, høyde og hvor mange tegn som får plass på en linje (i oppgaven, ellers i etiketten). */
const FORMER = {
  oppgave: { w: 128, h: 80, tegn: 18 },
  valg: { w: 48, h: 48, tegn: 22 },
  start: { w: 32, h: 32, tegn: 20 },
  slutt: { w: 32, h: 32, tegn: 20 },
};

/** Retningene, med klokka fra nord. Sidene på et element har samme nummer som retningen ut fra siden. */
const RETNING = [[0, -1], [1, 0], [0, 1], [-1, 0]];
const SIDE = { n: 0, o: 1, s: 2, v: 3 };

/** Deler teksten i linjer. «\n» i teksten er et tvunget linjeskift. */
export function brekk(tekst, tegn) {
  const linjer = [];
  for (const del of tekst.split('\n')) {
    let linje = '';
    for (const ord of del.split(' ')) {
      if (linje && (linje + ' ' + ord).length > tegn) {
        linjer.push(linje);
        linje = ord;
      } else {
        linje = linje ? linje + ' ' + ord : ord;
      }
    }
    linjer.push(linje);
  }
  return linjer;
}

const xml = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Regner ut hvor bassengene, banene og elementene står. */
function plasser(figur) {
  const eksterne = (figur.eksterne ?? []).map((e) => ({ plass: 'over', ...e }));
  const over = eksterne.filter((e) => e.plass === 'over');
  const under = eksterne.filter((e) => e.plass === 'under');

  const antallKol = Math.max(...figur.noder.map((n) => n[2])) + 1;
  const innholdX = MARG + 2 * HODE;
  const bredde = innholdX + antallKol * KOL + MARG;

  let y = MARG;
  for (const e of over) {
    Object.assign(e, { x0: MARG, x1: bredde - MARG, y0: y, y1: y + EKSTERN, kant: y + EKSTERN, side: 2 });
    y += EKSTERN + LUFT;
  }
  const bassengY0 = y;
  const baner = figur.baner.map((b) => {
    const bane = typeof b === 'string' ? { navn: b, rader: 1 } : { rader: 1, ...b };
    const plassert = { ...bane, y0: y, y1: y + bane.rader * RAD };
    y = plassert.y1;
    return plassert;
  });
  const bassengY1 = y;
  for (const e of under) {
    y += LUFT;
    Object.assign(e, { x0: MARG, x1: bredde - MARG, y0: y, y1: y + EKSTERN, kant: y, side: 0 });
    y += EKSTERN;
  }
  const hoyde = y + MARG;

  const noder = figur.noder.map(([id, bane, kol, type, tekst, valg = {}]) => {
    const form = FORMER[type];
    if (!form) throw new Error(`${figur.id}: ukjent type «${type}» på ${id}`);
    if (!baner[bane]) throw new Error(`${figur.id}: ${id} står i en bane som ikke finnes`);
    const linjer = brekk(tekst, form.tegn);
    if (linjer.length > (type === 'oppgave' ? 5 : 2)) throw new Error(`${figur.id}: teksten i ${id} er for lang (${linjer.length} linjer)`);
    const rad = valg.rad ?? (baner[bane].rader - 1) / 2;
    const cx = innholdX + KOL / 2 + kol * KOL;
    const cy = baner[bane].y0 + (rad + 0.5) * RAD;
    const n = { id, bane, kol, type, linjer, ikon: valg.ikon, cx, cy, w: form.w, h: form.h };
    Object.assign(n, { x0: cx - n.w / 2, x1: cx + n.w / 2, y0: cy - n.h / 2, y1: cy + n.h / 2 });
    if (type !== 'oppgave' && tekst) {
      // Hendelser og porter har teksten utenfor symbolet. Feltet sperres, så flyten ikke legges oppå teksten.
      const plass = valg.etikett ?? (type === 'valg' ? 'over' : 'under');
      const b = Math.max(...linjer.map((l) => l.length)) * TEGN + 8;
      const h = linjer.length * LINJE;
      n.etikett = plass === 'over'
        ? { plass, x0: cx - b / 2, x1: cx + b / 2, y0: n.y0 - 6 - h, y1: n.y0 - 2, forste: n.y0 - 9 - (linjer.length - 1) * LINJE }
        : { plass, x0: cx - b / 2, x1: cx + b / 2, y0: n.y1 + 2, y1: n.y1 + 6 + h, forste: n.y1 + 17 };
    }
    return n;
  });
  return { eksterne, baner, noder, bredde, hoyde, innholdX, bassengY0, bassengY1 };
}

/** Punktet midt på en side av elementet. */
function port(n, side) {
  return [[n.cx, n.y0], [n.x1, n.cy], [n.cx, n.y1], [n.x0, n.cy]][side];
}

/** En enkel prioritetskø (binær haug) for korteste vei-søket. */
class Ko {
  constructor() { this.d = []; }
  get tom() { return this.d.length === 0; }
  inn(prioritet, verdi) {
    const d = this.d;
    d.push([prioritet, verdi]);
    let i = d.length - 1;
    while (i > 0) {
      const f = (i - 1) >> 1;
      if (d[f][0] <= d[i][0]) break;
      [d[f], d[i]] = [d[i], d[f]];
      i = f;
    }
  }
  ut() {
    const d = this.d;
    const topp = d[0];
    const sist = d.pop();
    if (d.length) {
      d[0] = sist;
      let i = 0;
      for (;;) {
        const v = 2 * i + 1, h = v + 1;
        let m = i;
        if (v < d.length && d[v][0] < d[m][0]) m = v;
        if (h < d.length && d[h][0] < d[m][0]) m = h;
        if (m === i) break;
        [d[m], d[i]] = [d[i], d[m]];
        i = m;
      }
    }
    return topp[1];
  }
}

/**
 * Legger flyten. Hver linje går rettvinklet fra en side på startelementet til en side på målet, uten å krysse
 * elementer eller etiketter. Søket (A*) straffer svinger, kryssinger og det å gå oppå en annen linje. Flyt til samme
 * mål får lov til å gå sammen, slik at den ender i én pilspiss. Sekvensflyt holdes inne i bassenget.
 */
function leggFlyt(figur, plan) {
  const { noder, eksterne, bredde, hoyde, innholdX, bassengY0, bassengY1 } = plan;
  const finn = Object.fromEntries(noder.map((n) => [n.id, n]));
  const finnEkstern = Object.fromEntries(eksterne.map((e) => [e.id, e]));
  const kol = Math.floor(bredde / R) + 1;
  const rad = Math.floor(hoyde / R) + 1;
  const sperret = new Uint8Array(kol * rad);
  const naer = new Uint8Array(kol * rad);
  const ute = new Uint8Array(kol * rad);
  const inni = (x, y, f, luft) => x > f.x0 - luft && x < f.x1 + luft && y > f.y0 - luft && y < f.y1 + luft;

  for (let gy = 0; gy < rad; gy++) {
    for (let gx = 0; gx < kol; gx++) {
      const x = gx * R, y = gy * R, i = gy * kol + gx;
      if (x < innholdX + R || x > bredde - MARG - R || y < MARG + R || y > hoyde - MARG - R) sperret[i] = 1;
      if (y < bassengY0 + R || y > bassengY1 - R) ute[i] = 1;
      for (const e of eksterne) if (inni(x, y, e, R)) sperret[i] = 1;
      for (const n of noder) {
        if (inni(x, y, n, R) || (n.etikett && inni(x, y, n.etikett, 0))) sperret[i] = 1;
        else if (inni(x, y, n, 2 * R)) naer[i] = 1;
      }
    }
  }
  const fri = ([x, y]) => x >= 0 && y >= 0 && x < kol * R && y < rad * R && !sperret[y / R * kol + x / R];

  // Hva som alt ligger i rutenettet: hvilke linjer som bruker hvert linjestykke, og hvilke porter som er tatt.
  const vannrett = new Map(), loddrett = new Map(), porter = new Map();
  const stykke = (x, y, d) => (d === 1 ? [vannrett, `${x},${y}`] : d === 3 ? [vannrett, `${x - R},${y}`] : d === 2 ? [loddrett, `${x},${y}`] : [loddrett, `${x},${y - R}`]);
  const fremmed = (kart, nokkel, mal) => {
    const brukt = kart.get(nokkel);
    return brukt !== undefined && [...brukt].some((m) => m !== mal);
  };
  const krysser = (x, y, d, mal) => (d % 2 === 1
    ? fremmed(loddrett, `${x},${y}`, mal) || fremmed(loddrett, `${x},${y - R}`, mal)
    : fremmed(vannrett, `${x},${y}`, mal) || fremmed(vannrett, `${x - R},${y}`, mal));

  const sok = (start, startRetning, slutt, sluttSide, mal, melding) => {
    const [sx, sy] = start, [mx, my] = slutt;
    const inn = (sluttSide + 2) % 4;
    const beste = new Map();
    const ko = new Ko();
    const nokkel = (x, y, d) => (y / R * kol + x / R) * 4 + d;
    beste.set(nokkel(sx, sy, startRetning), 0);
    ko.inn(0, [sx, sy, startRetning, 0, null]);
    while (!ko.tom) {
      const post = ko.ut();
      const [x, y, d, kost] = post;
      if (kost > (beste.get(nokkel(x, y, d)) ?? Infinity)) continue;
      if (x === mx && y === my && d !== sluttSide) {
        const sti = [];
        for (let p = post; p; p = p[4]) sti.unshift([p[0], p[1]]);
        return { sti, kost: kost + (d === inn ? 0 : 4) };
      }
      for (const nd of [d, (d + 1) % 4, (d + 3) % 4]) {
        const nx = x + RETNING[nd][0] * R, ny = y + RETNING[nd][1] * R;
        if (!fri([nx, ny])) continue;
        const i = ny / R * kol + nx / R;
        const [kart, k] = stykke(x, y, nd);
        let pris = 1 + naer[i] * 0.6;
        if (nd !== d) pris += 4;
        if (!melding && ute[i]) pris += 8;
        if (fremmed(kart, k, mal)) pris += 60;
        if (krysser(nx, ny, nd, mal)) pris += 5;
        const nyKost = kost + pris;
        const nn = nokkel(nx, ny, nd);
        if (nyKost < (beste.get(nn) ?? Infinity)) {
          beste.set(nn, nyKost);
          ko.inn(nyKost + (Math.abs(nx - mx) + Math.abs(ny - my)) / R, [nx, ny, nd, nyKost, post]);
        }
      }
    }
    return null;
  };

  const ut = (p, side, steg) => [p[0] + RETNING[side][0] * steg * R, p[1] + RETNING[side][1] * steg * R];
  const merk = (punkter, mal) => {
    for (let i = 1; i < punkter.length; i++) {
      const [ax, ay] = punkter[i - 1], [bx, by] = punkter[i];
      const [kart, k] = ax === bx ? [loddrett, `${ax},${Math.min(ay, by)}`] : [vannrett, `${Math.min(ax, bx)},${ay}`];
      if (!kart.has(k)) kart.set(k, new Set());
      kart.get(k).add(mal);
    }
  };
  const taPort = (id, side, slag, mal) => {
    const k = `${id}:${side}`;
    if (!porter.has(k)) porter.set(k, { ut: new Set(), inn: 0 });
    if (slag === 'ut') porter.get(k).ut.add(mal);
    else porter.get(k).inn++;
  };

  // Meldingsflyten legges først. Den går som regel rett opp eller ned, og sekvensflyten legges rundt den.
  const meldinger = (figur.meldinger ?? []).map(([fraId, tilId, etikett = '', valg = {}], nr) => {
    const fraEkstern = finnEkstern[fraId], tilEkstern = finnEkstern[tilId];
    const node = finn[fraEkstern ? tilId : fraId], ekstern = fraEkstern ?? tilEkstern;
    if (!node || !ekstern) throw new Error(`${figur.id}: meldingen ${fraId} → ${tilId} må gå mellom et element og en ekstern deltaker`);
    const nodeSide = ekstern.plass === 'over' ? 0 : 2;
    const vedNode = port(node, nodeSide);
    vedNode[0] += valg.dx ?? 0;
    const vedEkstern = [vedNode[0], ekstern.kant];
    const [p, us, q, is] = fraEkstern ? [vedEkstern, ekstern.side, vedNode, nodeSide] : [vedNode, nodeSide, vedEkstern, ekstern.side];
    const funn = sok(ut(p, us, 2), us, ut(q, is, 2), is, `melding:${nr}`, true);
    if (!funn) throw new Error(`${figur.id}: fant ingen vei for meldingen ${fraId} → ${tilId}`);
    const punkter = [p, ut(p, us, 1), ...funn.sti, ut(q, is, 1), q];
    merk(punkter, `melding:${nr}`);
    taPort(node.id, nodeSide, 'inn', '');
    return { punkter, etikett, us, melding: true };
  });

  const flyt = figur.flyt.map(([fraId, tilId, etikett = '', valg = {}]) => {
    const fra = finn[fraId], til = finn[tilId];
    if (!fra || !til) throw new Error(`${figur.id}: flyten ${fraId} → ${tilId} peker på et element som ikke finnes`);
    const utSider = valg.fra ? [SIDE[valg.fra]] : [1, 2, 0, 3];
    const innSider = valg.til ? [SIDE[valg.til]] : [3, 0, 2, 1];
    let best = null;
    for (const us of utSider) {
      for (const is of innSider) {
        const p = port(fra, us), q = port(til, is);
        if (![ut(p, us, 1), ut(p, us, 2), ut(q, is, 1), ut(q, is, 2)].every(fri)) continue;
        const funn = sok(ut(p, us, 2), us, ut(q, is, 2), is, tilId, false);
        if (!funn) continue;
        let kost = funn.kost + [1, 0, 1, 6][us] + [2, 8, 2, 0][is];
        if (til.type === 'valg' && is !== 3) kost += 25;
        const utPort = porter.get(`${fraId}:${us}`), innPort = porter.get(`${tilId}:${is}`);
        if (utPort && (utPort.inn || [...utPort.ut].some((m) => m !== tilId))) kost += 40;
        if (innPort && innPort.ut.size) kost += 40;
        if (!best || kost < best.kost) best = { kost, us, is, p, q, sti: funn.sti };
      }
    }
    if (!best) throw new Error(`${figur.id}: fant ingen vei for flyten ${fraId} → ${tilId}`);
    const { us, is, p, q, sti } = best;
    const punkter = [p, ut(p, us, 1), ...sti, ut(q, is, 1), q];
    merk(punkter, tilId);
    taPort(fraId, us, 'ut', tilId);
    taPort(tilId, is, 'inn', '');
    return { punkter, etikett, us, melding: false };
  });

  return [...meldinger, ...flyt];
}

/** Fjerner punkter som ligger på en rett linje, så bare hjørnene står igjen. */
function hjorner(punkter) {
  const ut = [punkter[0]];
  for (let i = 1; i < punkter.length - 1; i++) {
    const [ax, ay] = ut[ut.length - 1], [bx, by] = punkter[i], [cx, cy] = punkter[i + 1];
    if ((ax === bx && bx === cx) || (ay === by && by === cy)) continue;
    ut.push(punkter[i]);
  }
  ut.push(punkter[punkter.length - 1]);
  return ut;
}

/** Linjen som en SVG-sti med avrundede hjørner, kortet inn så den ikke stikker gjennom pilspissen. */
function sti(punkter) {
  const h = hjorner(punkter);
  const [sx, sy] = h[h.length - 1], [fx, fy] = h[h.length - 2];
  const lengde = Math.hypot(sx - fx, sy - fy);
  const ende = [sx - (sx - fx) / lengde * 7, sy - (sy - fy) / lengde * 7];
  let d = `M${h[0][0]},${h[0][1]}`;
  for (let i = 1; i < h.length - 1; i++) {
    const [ax, ay] = h[i - 1], [bx, by] = h[i], [cx, cy] = h[i + 1];
    const inn = Math.hypot(bx - ax, by - ay), utover = Math.hypot(cx - bx, cy - by);
    const r = Math.min(6, inn / 2, utover / 2);
    d += ` L${bx - (bx - ax) / inn * r},${by - (by - ay) / inn * r} Q${bx},${by} ${bx + (cx - bx) / utover * r},${by + (cy - by) / utover * r}`;
  }
  return `${d} L${ende[0]},${ende[1]}`;
}

function pilspiss(punkter) {
  const [sx, sy] = punkter[punkter.length - 1], [fx, fy] = punkter[punkter.length - 2];
  const dx = Math.sign(sx - fx), dy = Math.sign(sy - fy);
  const bak = [sx - dx * 9, sy - dy * 9];
  return `${sx},${sy} ${bak[0] - dy * 4.5},${bak[1] + dx * 4.5} ${bak[0] + dy * 4.5},${bak[1] - dx * 4.5}`;
}

function tegnFlyt(f) {
  if (!f.melding) return `<path class="bp-flyt" d="${sti(f.punkter)}"/><polygon class="bp-pil" points="${pilspiss(f.punkter)}"/>`;
  const [x, y] = f.punkter[0];
  return `<path class="bp-melding" d="${sti(f.punkter)}"/><polygon class="bp-meldingspil" points="${pilspiss(f.punkter)}"/><circle class="bp-meldingspil" cx="${x}" cy="${y}" r="3.5"/>`;
}

/** Ikonet inne i en hendelse: konvolutt for melding, klokke for tid. */
function ikon(n) {
  const { cx, cy } = n;
  if (n.ikon === 'melding') {
    return `<rect class="bp-ikon" x="${cx - 8}" y="${cy - 5.5}" width="16" height="11"/><path class="bp-ikon" d="M${cx - 8},${cy - 5.5} L${cx},${cy + 1} L${cx + 8},${cy - 5.5}"/>`;
  }
  if (n.ikon === 'tid') {
    return `<circle class="bp-ikon" cx="${cx}" cy="${cy}" r="9"/><path class="bp-ikon" d="M${cx},${cy - 6} V${cy} H${cx + 4.5}"/>`;
  }
  return '';
}

function tekstlinjer(linjer, x, forste, klasse) {
  return `<text class="${klasse}" text-anchor="middle">${linjer.map((l, i) => `<tspan x="${x}" y="${forste + i * LINJE}">${xml(l)}</tspan>`).join('')}</text>`;
}

function tegnNode(n) {
  if (n.type === 'oppgave') {
    const forste = n.cy - (n.linjer.length - 1) * LINJE / 2 + 4;
    return `<rect class="bp-oppgave" x="${n.x0}" y="${n.y0}" width="${n.w}" height="${n.h}" rx="10"/>${tekstlinjer(n.linjer, n.cx, forste, 'bp-tekst')}`;
  }
  const etikett = n.etikett ? tekstlinjer(n.linjer, n.cx, n.etikett.forste, 'bp-etikett') : '';
  if (n.type === 'valg') {
    const k = 7;
    return `<polygon class="bp-valg" points="${n.cx},${n.y0} ${n.x1},${n.cy} ${n.cx},${n.y1} ${n.x0},${n.cy}"/>`
      + `<path class="bp-kryss" d="M${n.cx - k},${n.cy - k} L${n.cx + k},${n.cy + k} M${n.cx + k},${n.cy - k} L${n.cx - k},${n.cy + k}"/>${etikett}`;
  }
  return `<circle class="bp-${n.type}" cx="${n.cx}" cy="${n.cy}" r="${n.w / 2 - (n.type === 'slutt' ? 1.5 : 0)}"/>${ikon(n)}${etikett}`;
}

function tegnFlytetikett(f) {
  if (!f.etikett) return '';
  const [x, y] = f.punkter[0];
  const [tx, ty, anker] = [[x + 6, y - 7, 'start'], [x + 5, y - 6, 'start'], [x + 6, y + 15, 'start'], [x - 5, y - 6, 'end']][f.us];
  return `<text class="bp-etikett bp-flytetikett" x="${tx}" y="${ty}" text-anchor="${anker}">${xml(f.etikett)}</text>`;
}

/** Navn som står på høykant i feltet til venstre, lest nedenfra og opp, slik BPMN-verktøy viser basseng og baner. */
function hoykant(tekst, x, y, klasse, plass) {
  let linjer = [tekst];
  if (tekst.length * 6.6 > plass - 12 && tekst.includes(' ')) {
    const ord = tekst.split(' ');
    let best = 1;
    for (let i = 1; i < ord.length; i++) {
      const skjevhet = (n) => Math.abs(ord.slice(0, n).join(' ').length - ord.slice(n).join(' ').length);
      if (skjevhet(i) < skjevhet(best)) best = i;
    }
    linjer = [ord.slice(0, best).join(' '), ord.slice(best).join(' ')];
  }
  const forste = linjer.length === 1 ? 4.5 : -3;
  return `<text class="${klasse}" text-anchor="middle" transform="translate(${x},${y}) rotate(-90)">`
    + linjer.map((l, i) => `<tspan x="0" y="${forste + i * 14}">${xml(l)}</tspan>`).join('') + '</text>';
}

const farge = (token, lys) => `var(--bivrost-farge-${token}, ${lys})`;

/**
 * Fargene. Hver farge er et token i designpakken. Verdien etter kommaet er Marine i lys modus og brukes når figuren
 * står alene som fil. Blokken for mørk modus gjelder også bare da: på en side med designpakken bestemmer tokenene.
 */
export const STIL = [
  `.bp-flate{fill:${farge('bakgrunn-flate-1', '#FFFFFF')}}`,
  `.bp-hode{fill:${farge('bakgrunn-flate-3', '#F0F0F0')}}`,
  `.bp-ramme{fill:none;stroke:${farge('kant-sterk', '#616161')}}`,
  `.bp-skille{stroke:${farge('kant-sterk', '#616161')}}`,
  `.bp-navn{fill:${farge('tekst-standard', '#242424')};font-weight:600;font-size:12px}`,
  `.bp-oppgave{fill:${farge('bakgrunn-flate-1', '#FFFFFF')};stroke:${farge('tekst-standard', '#242424')};stroke-width:1.25}`,
  `.bp-valg,.bp-start,.bp-slutt{fill:${farge('bakgrunn-flate-1', '#FFFFFF')};stroke:${farge('tekst-merkevare', '#003366')};stroke-width:1.5}`,
  '.bp-slutt{stroke-width:4}',
  `.bp-kryss{fill:none;stroke:${farge('tekst-merkevare', '#003366')};stroke-width:2.5;stroke-linecap:round}`,
  `.bp-ikon{fill:none;stroke:${farge('tekst-merkevare', '#003366')};stroke-width:1.25;stroke-linejoin:round}`,
  `.bp-tekst{fill:${farge('tekst-standard', '#242424')};font-size:12px}`,
  `.bp-etikett{fill:${farge('tekst-standard', '#242424')};font-size:12px;paint-order:stroke;stroke:${farge('bakgrunn-flate-1', '#FFFFFF')};stroke-width:4px;stroke-linejoin:round}`,
  `.bp-flytetikett{fill:${farge('tekst-merkevare', '#003366')};font-weight:600}`,
  `.bp-flyt{fill:none;stroke:${farge('tekst-dempet', '#424242')};stroke-width:1.5}`,
  `.bp-pil{fill:${farge('tekst-dempet', '#424242')}}`,
  `.bp-melding{fill:none;stroke:${farge('tekst-dempet', '#424242')};stroke-width:1.25;stroke-dasharray:6 4}`,
  `.bp-meldingspil{fill:${farge('bakgrunn-flate-1', '#FFFFFF')};stroke:${farge('tekst-dempet', '#424242')};stroke-width:1.25}`,
].join('');

const STIL_MORK = '@media (prefers-color-scheme: dark){svg{'
  + '--bivrost-farge-bakgrunn-flate-1:#292929;--bivrost-farge-bakgrunn-flate-3:#0A0A0A;--bivrost-farge-kant-sterk:#ADADAD;'
  + '--bivrost-farge-tekst-standard:#FFFFFF;--bivrost-farge-tekst-dempet:#D6D6D6;--bivrost-farge-tekst-merkevare:#33CCFF}}';

/**
 * Lager SVG-en for én figur.
 *
 * @param {object} figur  Beskrivelsen fra figurer.mjs.
 * @param {{medStil?: boolean}} valg  medStil = false utelater stilen, for sider som legger inn STIL én gang selv.
 */
export function tegn(figur, { medStil = true } = {}) {
  const plan = plasser(figur);
  const flyt = leggFlyt(figur, plan);
  const { eksterne, baner, noder, bredde, hoyde, bassengY0, bassengY1 } = plan;
  const x0 = MARG, x1 = bredde - MARG;

  const rekkefolge = [...noder].sort((a, b) => a.kol - b.kol || a.cy - b.cy);
  const beskrivelse = `BPMN-diagram. Basseng: ${figur.basseng}. Baner: ${baner.map((b) => b.navn).join(', ')}.`
    + (eksterne.length ? ` Eksterne deltakere: ${eksterne.map((e) => e.navn).join(', ')}.` : '')
    + ' Elementer i rekkefølge: ' + rekkefolge.map((n) => `${baner[n.bane].navn}: ${n.linjer.join(' ')}`).join('. ') + '.';

  const deler = [
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${bredde} ${hoyde}" width="${bredde}" height="${hoyde}" role="img" font-family="'Segoe UI', 'Gerd Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif" font-size="13">`,
    `<title>${xml(figur.tittel)}</title><desc>${xml(beskrivelse)}</desc>`,
    medStil ? `<style>${STIL}${STIL_MORK}</style>` : '',
    ...eksterne.map((e) => `<rect class="bp-flate" x="${e.x0}" y="${e.y0}" width="${e.x1 - e.x0}" height="${EKSTERN}"/><rect class="bp-ramme" x="${e.x0}" y="${e.y0}" width="${e.x1 - e.x0}" height="${EKSTERN}"/>`
      + `<text class="bp-navn" x="${e.x0 + 16}" y="${e.y0 + EKSTERN / 2 + 4.5}">${xml(e.navn)}</text>`),
    `<rect class="bp-flate" x="${x0}" y="${bassengY0}" width="${x1 - x0}" height="${bassengY1 - bassengY0}"/>`,
    `<rect class="bp-hode" x="${x0}" y="${bassengY0}" width="${2 * HODE}" height="${bassengY1 - bassengY0}"/>`,
    hoykant(figur.basseng, x0 + HODE / 2, (bassengY0 + bassengY1) / 2, 'bp-navn', bassengY1 - bassengY0),
    ...baner.map((b) => hoykant(b.navn, x0 + HODE * 1.5, (b.y0 + b.y1) / 2, 'bp-navn', b.y1 - b.y0)),
    ...baner.slice(1).map((b) => `<line class="bp-skille" x1="${x0 + HODE}" y1="${b.y0}" x2="${x1}" y2="${b.y0}"/>`),
    `<line class="bp-skille" x1="${x0 + HODE}" y1="${bassengY0}" x2="${x0 + HODE}" y2="${bassengY1}"/>`,
    `<line class="bp-skille" x1="${x0 + 2 * HODE}" y1="${bassengY0}" x2="${x0 + 2 * HODE}" y2="${bassengY1}"/>`,
    `<rect class="bp-ramme" x="${x0}" y="${bassengY0}" width="${x1 - x0}" height="${bassengY1 - bassengY0}"/>`,
    ...flyt.map(tegnFlyt),
    ...noder.map(tegnNode),
    ...flyt.map(tegnFlytetikett),
    '</svg>',
  ];
  return deler.filter(Boolean).join('\n') + '\n';
}
