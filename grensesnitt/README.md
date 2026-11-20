# @bivrost/grensesnitt – designpakken

Utseendet i alle appene som bruker Bivrost (BIV-B-011, BIV-B-012): «Bivrost skal ha samme grensesnitt som Gerd.»

| Mappe | Innhold |
|---|---|
| `tokens/` | W3C Design Tokens: `grunnlag.tokens.json` og 20 fargesett `tema/<tema>.<lys|mork>.<normal|hoy>.tokens.json`; `style-dictionary.config.json` |
| `stil/bivrost.css` | Grunnfilen: CSS-variablene (`--bivrost-…`), skriften, fokus, tvungne farger, redusert bevegelse og utskrift (Marine, lys, uten temafil) |
| `stil/tema/<tema>.css` | Fargene for ett tema i lys, mørk og høy kontrast (BIV-B-028). Lenkes etter grunnfilen med `data-bivrost-tema-fil="aktiv"`; filen som er lastet, bestemmer fargene. `bruk(el, u, document)` i `src/preferanser.ts` bytter den uten blink, og beholder den gamle om hentingen feiler |
| `logo/` | Bivrost-logoen: logo, liten logo, favicon, appikon, ordmerke og én-farges versjon (`currentColor`). Samme sekskantramme og murstil som Gerð: en steinbro i oransje over vann i Gerðs blåfarger |
| `fonter/` | Gerd Sans (Microsofts Selawik, SIL OFL 1.1) med lisens |
| `ikoner/` | Felles strekikoner (status, filter, sortering, bruker, lås …) |
| `src/` | `farger.ts` (OKLab, WCAG-kontrast), `temaer.ts`, `fluent.ts` (Fluent UI v9-tema fra tokenene), `preferanser.ts` (attributtene og localStorage), `i18n.ts` (språkfilene til react-intl), `kontrastregler.ts` |
| `skript/` | `lag-tokens.ts` (genererer alt), `sjekk-kontrast.ts`, `sjekk-faste-farger.ts` |

```
npm ci --ignore-scripts
npm run tokens     # lager tokens/, stil/ og ../docs/designtokens.md på nytt
npm run sjekk      # i takt, kontrasten (4 300 par) og ingen faste farger
npm test
```

Tokenene er beskrevet i `../docs/designtokens.md`. Kjerne leverer stilfilen, skriften, ikonene og tokenene fra appens
egen server under `/bivrost/ressurser/` (ingen CDN, ingen avhengighet til Sentral).

## Komponentene (fase 3)
Flyttes fra Gerð-prototypen og bygges inn i appene (React + Fluent UI v9): skall med menyer og brukermeny (tema, modus,
bredde, tetthet, kontrast, bevegelse, språk, bytt passord), sorterbar tabell med filterikon og «X av Y» (B-043), tre,
statusmerker (ikon + tekst + farge), feil og lasting, hjelperamme, «Notér erfaring»-dialogen (B-058), loggvisningen,
og språkmotoren (react-intl, `useT()`, filene lastet med `import.meta.glob`). Alle etter WCAG 2.2 AA og WAI-ARIA APG.
