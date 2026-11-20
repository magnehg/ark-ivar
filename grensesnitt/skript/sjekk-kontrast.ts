// Kontrollerer kontrasten (WCAG 2.2 AA, og AAA for tekst i høy kontrast) for alle tokenfilene i tokens/tema.
// Kjør: node --experimental-strip-types skript/sjekk-kontrast.ts [--vis]   (feilkode 1 hvis et krav ikke er oppfylt)
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { kontrast } from '../src/farger.ts';
import { regler } from '../src/kontrastregler.ts';
import { slaaOpp, type Gruppe } from './generator.ts';

const mappe = join(dirname(fileURLToPath(import.meta.url)), '..', 'tokens', 'tema');
const vis = process.argv.includes('--vis');
let feil = 0, antall = 0;
for (const fil of readdirSync(mappe).filter((f) => f.endsWith('.tokens.json')).sort()) {
  const dok = JSON.parse(readFileSync(join(mappe, fil), 'utf8')) as Gruppe;
  const hoy = fil.includes('.hoy.');
  for (const r of regler()) {
    const a = slaaOpp(dok, `{${r.forgrunn}}`) as string, b = slaaOpp(dok, `{${r.bakgrunn}}`) as string;
    if (/^#[0-9A-F]{8}$/i.test(a) || /^#[0-9A-F]{8}$/i.test(b)) continue;
    const krav = r.slag === 'tekst' ? (hoy ? 7 : 4.5) : r.slag === 'etikett' ? 4.5 : 3;
    const k = kontrast(a, b);
    antall++;
    if (k < krav) feil++;
    if (k < krav || vis) console.log(`${k >= krav ? 'OK  ' : 'FEIL'} ${fil.replace('.tokens.json', '').padEnd(24)} ${k.toFixed(2).padStart(5)} (krav ${krav})  ${r.hva}: ${r.forgrunn} ${a} på ${r.bakgrunn} ${b}`);
  }
}
console.log(feil ? `\n${feil} av ${antall} kontrastkrav er ikke oppfylt.` : `Alle ${antall} kontrastkrav er oppfylt.`);
process.exit(feil ? 1 : 0);
