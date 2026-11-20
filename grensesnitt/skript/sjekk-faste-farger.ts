// Finner faste farger i kode (BIV-GRS-06): appene skal bruke tokenene, ikke #hex, rgb() eller hsl().
// Kjør: node --experimental-strip-types skript/sjekk-faste-farger.ts <mappe> [<mappe> …]
// Filer som begynner med kommentaren «bivrost: fargekilde» (temaene og generatoren) og genererte filer hoppes over.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const MONSTER = /#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/g;
const ENDINGER = /\.(ts|tsx|js|jsx|css|scss|vue|svelte|html|php)$/;
let funn = 0;
function gaa(sti: string): void {
  const st = statSync(sti);
  if (st.isDirectory()) {
    for (const n of readdirSync(sti)) if (!['node_modules', 'vendor', 'bygg', 'dist'].includes(n)) gaa(join(sti, n));
    return;
  }
  if (!ENDINGER.test(sti)) return;
  const tekst = readFileSync(sti, 'utf8');
  if (/^(\/\/|\/\*|<\?php\s*\/\/)\s*bivrost: fargekilde/.test(tekst) || /Generert av grensesnitt\/skript\/lag-tokens\.ts/.test(tekst.slice(0, 400))) return;
  tekst.split('\n').forEach((linje, i) => {
    if (/^\s*(\/\/|\*|\/\*)/.test(linje) || /bivrost-ignorer-farge/.test(linje)) return;
    for (const m of linje.matchAll(MONSTER)) {
      // Entiteter i HTML (&#123;) er ikke farger.
      if (m[0].startsWith('#') && linje[(m.index ?? 0) - 1] === '&') continue;
      console.log(`${sti}:${i + 1}: fast farge «${m[0]}» – bruk et token (var(--bivrost-…) eller Fluent-temaet)`);
      funn++;
    }
  });
}
const mapper = process.argv.slice(2);
if (mapper.length === 0) { console.error('Bruk: sjekk-faste-farger.ts <mappe> …'); process.exit(2); }
mapper.forEach(gaa);
console.log(funn ? `\n${funn} faste farger.` : 'Ingen faste farger.');
process.exit(funn ? 1 : 0);
