// Kontrastparene som kontrolleres i alle temaene, modusene og kontrastnivåene (WCAG 2.2: 1.4.3 tekst 4,5:1,
// 1.4.6 tekst 7:1 i høy kontrast, 1.4.11 grafikk og grenser for kontroller 3:1, 2.4.11/2.4.13 fokus 3:1).
// Stiene er i tokenfilene. «tekst» får kravet 7 i høy kontrast; «grafikk» er 3 i begge.
// «etikett» (tekst på farger i diagrammer) har 4,5:1 også i høy kontrast: midttrinnene i en skala kan ikke gi 7:1.
export type Regel = { forgrunn: string; bakgrunn: string; slag: 'tekst' | 'etikett' | 'grafikk'; hva: string };

const FLATER = ['farge.bakgrunn.side', 'farge.bakgrunn.flate-1', 'farge.bakgrunn.flate-2'];
const STATUSER = ['ok', 'advarsel', 'feil', 'info', 'pagar', 'noytral'];
const LAG = ['strategi', 'forretning', 'applikasjon', 'teknologi', 'fysisk', 'motivasjon', 'implementering'];

export function regler(): Regel[] {
  const r: Regel[] = [];
  const t = (forgrunn: string, bakgrunn: string, hva: string) => r.push({ forgrunn, bakgrunn, slag: 'tekst', hva });
  const gr = (forgrunn: string, bakgrunn: string, hva: string) => r.push({ forgrunn, bakgrunn, slag: 'grafikk', hva });
  for (const f of FLATER) {
    t('farge.tekst.standard', f, 'tekst');
    t('farge.tekst.dempet', f, 'sekundærtekst');
    t('farge.tekst.lenke', f, 'lenke');
    t('farge.tekst.lenke-besokt', f, 'besøkt lenke');
    t('farge.tekst.merkevare', f, 'merkevaretekst');
    gr('farge.kant.sterk', f, 'grense for kontroll');
    gr('farge.kant.fokus', f, 'fokusring');
    gr('farge.kant.valgt', f, 'markering av valgt');
    gr('farge.merkevare.bakgrunn', f, 'primærknapp mot flaten');
    for (const s of STATUSER) {
      gr(`farge.status.${s}.ikon`, f, `statusikon ${s}`);
      gr(`farge.status.${s}.kant`, f, `statuskant ${s}`);
      t(`farge.status.${s}.tekst`, f, `statustekst ${s} på flate`);
    }
    for (let i = 1; i <= 8; i++) gr(`farge.data.kategorisk.${i}`, f, `kategori ${i} i diagram`);
    gr('farge.data.sekvensiell.7', f, 'sterkeste trinn i sekvensiell skala');
    gr('farge.data.divergerende.1', f, 'ende i divergerende skala');
    gr('farge.data.divergerende.9', f, 'ende i divergerende skala');
    for (const l of LAG) gr(`farge.archimate.${l}.kant`, f, `kant på ArchiMate-laget ${l}`);
  }
  for (const b of ['farge.merkevare.bakgrunn', 'farge.merkevare.bakgrunn-hover', 'farge.merkevare.bakgrunn-trykket', 'farge.merkevare.bakgrunn-valgt']) t('farge.tekst.pa-merkevare', b, 'tekst på primærknapp');
  for (const b of ['farge.bakgrunn.valgt', 'farge.bakgrunn.valgt-hover']) {
    t('farge.tekst.standard', b, 'tekst i valgt element');
    t('farge.tekst.dempet', b, 'sekundærtekst i valgt element');
    t('farge.tekst.lenke', b, 'lenke i valgt rad');
    gr('farge.kant.valgt', b, 'stripe på valgt element');
  }
  for (const b of ['farge.bakgrunn.hover', 'farge.bakgrunn.trykket']) {
    t('farge.tekst.standard', b, 'tekst med pekeren over');
    gr('farge.kant.fokus', b, 'fokusring med pekeren over');
  }
  for (const s of STATUSER) {
    t(`farge.status.${s}.tekst`, `farge.status.${s}.bakgrunn`, `statustekst ${s}`);
    t('farge.tekst.standard', `farge.status.${s}.bakgrunn`, `vanlig tekst i statusboks ${s}`);
    gr(`farge.status.${s}.ikon`, `farge.status.${s}.bakgrunn`, `statusikon ${s} i statusboks`);
  }
  const et = (forgrunn: string, bakgrunn: string, hva: string) => r.push({ forgrunn, bakgrunn, slag: 'etikett', hva });
  for (let i = 1; i <= 7; i++) et(`farge.data.tekst-pa-sekvensiell.${i}`, `farge.data.sekvensiell.${i}`, `etikett på trinn ${i}`);
  for (let i = 1; i <= 9; i++) et(`farge.data.tekst-pa-divergerende.${i}`, `farge.data.divergerende.${i}`, `etikett på trinn ${i}`);
  for (const l of LAG) {
    t(`farge.archimate.${l}.tekst`, `farge.archimate.${l}.fyll`, `navn i boks i laget ${l}`);
    gr(`farge.archimate.${l}.kant`, `farge.archimate.${l}.fyll`, `kant på boks i laget ${l}`);
    gr('farge.kant.fokus', `farge.archimate.${l}.fyll`, `fokusring i laget ${l}`);
  }
  t('farge.toppfelt.tekst', 'farge.toppfelt.bakgrunn', 'tekst i toppfeltet');
  gr('farge.toppfelt.logo-fyll', 'farge.toppfelt.bakgrunn', 'logo (aksent) i toppfeltet');
  gr('farge.toppfelt.logo-ramme', 'farge.toppfelt.bakgrunn', 'logo (ramme) i toppfeltet');
  // Fluent-parene fra Gerð (B-040/B-041): komponentene bruker Fluents egne navn.
  t('fluent.colorNeutralForegroundOnBrand', 'fluent.colorBrandBackgroundSelected', 'tekst på valgt filterknapp');
  gr('fluent.colorCompoundBrandBackground', 'fluent.colorNeutralBackground1', 'avkrysset boks');
  gr('fluent.colorNeutralForegroundInverted', 'fluent.colorCompoundBrandBackground', 'hake i avkrysset boks');
  gr('fluent.colorBrandStroke1', 'fluent.colorNeutralBackground1', 'markering av valgt fane');
  t('fluent.colorBrandForeground1', 'fluent.colorNeutralBackground1Hover', 'merkevaretekst med pekeren over');
  t('fluent.colorNeutralForeground1', 'fluent.colorPaletteYellowBackground2', 'uthevet søketreff');
  return r;
}
