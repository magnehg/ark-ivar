# Figurene i dokumentasjonen

Prosessene i arkivplanen er tegnet etter BPMN 2.0, med kommunen som basseng og rollene som baner. Mermaid, som de
andre figurene bruker, har ikke basseng og baner. BPMN-figurene lages derfor av en liten tegnemotor uten
avhengigheter.

| Fil | Innhold |
|---|---|
| `figurer.mjs` | Beskrivelsene av figurene |
| `bpmn.mjs` | Tegnemotoren: plasserer elementene, legger flyten og skriver SVG |
| `lag-figurer.mjs` | Skriver `<id>.svg` for hver figur |
| `*.svg` | De ferdige figurene. De ligger i git, fordi Markdown-dokumentene viser dem som bilder |

```
node docs/figurer/lag-figurer.mjs            # lager filene på nytt
node docs/figurer/lag-figurer.mjs --sjekk    # feiler hvis en fil ikke er i takt
```

## Slik beskrives en figur

```js
{
  id: 'p4-utgaende',                      // filnavnet
  tittel: 'P4 Utgående dokument',         // tittelen i SVG-en (for skjermlesere)
  basseng: 'Sirevåg kommune',
  eksterne: [{ id: 'mottaker', navn: 'Mottakeren', plass: 'over' }],   // sammenslåtte basseng, over eller under
  baner: [{ navn: 'Saksbehandler', rader: 2 }, 'Leder'],              // rader: plass til to elementer i høyden
  noder: [
    // [id, bane, kolonne, type, tekst, valg]
    ['start', 0, 0, 'start', 'Dokument skal sendes', { rad: 0 }],
    ['skj', 0, 2, 'valg', 'Må noe skjermes?', { rad: 0, etikett: 'under' }],
  ],
  flyt: [
    // [fra, til, etikett, valg]
    ['skj', 'kode', 'ja', { fra: 's', til: 'v' }],
  ],
  meldinger: [['send', 'mottaker', 'Dokumentet']],
}
```

| Type | BPMN-symbol |
|---|---|
| `start` | Starthendelse. `ikon: 'melding'` gir konvolutt, `ikon: 'tid'` gir klokke |
| `slutt` | Slutthendelse |
| `oppgave` | Oppgave. Teksten står inne i symbolet, høyst fem linjer |
| `valg` | Eksklusiv port. Spørsmålet står over porten, eller under med `etikett: 'under'` |

Tegnemotoren legger flyten selv. Den velger sider på elementene, går rundt elementer og etiketter, og lar flyt til
samme mål gå sammen. `fra` og `til` (`n`, `o`, `s`, `v`) tvinger en bestemt side når resultatet blir uryddig.
Etiketter på hendelser og porter kan ha høyst to linjer.

## Farger

Fargene er tokenene i designpakken (`grensesnitt/`), skrevet som `var(--bivrost-farge-…, reserve)`. Reserven er
Marine i lys modus, med egne verdier for mørk modus når figuren står alene som fil. På en side som laster
designpakken, følger figurene temaet.

Mermaid-figurene har de samme fargene i en `%%{init: …}%%`-linje øverst i hver figur.
