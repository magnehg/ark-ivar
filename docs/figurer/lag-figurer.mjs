#!/usr/bin/env node
/**
 * Skriver BPMN-figurene i figurer.mjs som SVG-filer i denne mappen.
 *
 *   node docs/figurer/lag-figurer.mjs            lager filene på nytt
 *   node docs/figurer/lag-figurer.mjs --sjekk    feiler hvis en fil ikke er i takt med beskrivelsen
 *
 * Filene ligger i git, fordi Markdown-dokumentene viser dem som bilder.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { tegn } from './bpmn.mjs';
import { FIGURER } from './figurer.mjs';

const mappe = path.dirname(fileURLToPath(import.meta.url));
const sjekk = process.argv.includes('--sjekk');
const ider = new Set();
let avvik = 0;

for (const figur of FIGURER) {
  if (ider.has(figur.id)) throw new Error(`To figurer heter ${figur.id}`);
  ider.add(figur.id);
  const fil = path.join(mappe, `${figur.id}.svg`);
  const svg = tegn(figur);
  if (!sjekk) {
    fs.writeFileSync(fil, svg);
  } else if (!fs.existsSync(fil) || fs.readFileSync(fil, 'utf8') !== svg) {
    console.error(`Ikke i takt: ${figur.id}.svg`);
    avvik++;
  }
}

for (const fil of fs.readdirSync(mappe).filter((f) => f.endsWith('.svg'))) {
  if (!ider.has(fil.slice(0, -4))) {
    console.error(`Mangler beskrivelse: ${fil}`);
    avvik++;
  }
}

if (avvik) {
  console.error('Kjør node docs/figurer/lag-figurer.mjs og legg filene i git.');
  process.exit(1);
}
console.log(`${FIGURER.length} figurer ${sjekk ? 'er i takt' : 'skrevet'}.`);
