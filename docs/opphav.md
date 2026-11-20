# Opphavet til Kjerne og designpakken

Ark-Ivar bygger på en kopi av Bivrost (`magnehg/bivrost`), men er ikke koblet til Bivrost: ingen innlogging mot
Bivrost Sentral, ingen oppdateringer fra Bivrost-repoet og ingen felles brukere med de andre appene.

| Mappe | Kopiert fra | Versjon |
|---|---|---|
| `kjerne/` | `kjerne/` i Bivrost | Bivrost Kjerne 0.2.0, commit `fad4e700918b7106cba2989ea6ff10cc1ea8b488` (2026-11-20) |
| `grensesnitt/` | `grensesnitt/` i Bivrost | designpakken 0.1.0, samme commit |
| `skript/` | `skript/` i Bivrost | samme commit |

Den første commiten i dette repoet er den uendrede kopien. `git diff 56a7c53 -- kjerne grensesnitt skript` viser
altså alt Ark-Ivar har endret.

## Det Ark-Ivar har endret i kopien

Navnerommet (`Bivrost\Kjerne\`), tabellprefikset (`bivrost_`) og rutene (`/bivrost/…`) er beholdt, så kopien er lett
å sammenligne med Bivrost og rettelser derfra kan hentes inn for hånd.

| Endring | Hvor | Hvorfor |
|---|---|---|
| Appens egne brukere: grensesnittet `Okt\Appbrukere`, `Kjerne::settAppbrukere()`, innloggingsmetoden `app` i `Okt\Innlogget`, og `metoder.app` i svaret fra `GET /bivrost/api/okt` | `kjerne/src/Okt/Appbrukere.php`, `Innlogget.php`, `Kjerne.php`, `openapi.json` | Bivrost forutsetter at vanlige brukere ligger i Bivrost Sentral, og har bare lokale driftsbrukere. Ark-Ivar har studentene, lærerne og administratorene selv. Kjerne spør appen ved hver forespørsel om innloggingen fortsatt gjelder. |
| Appen får sidene sine også før installasjonen: `Kjerne::installert()`, og GET-forespørsler utenfor `/bivrost/` går til appen når den ikke er installert (ellers 503 som før) | `kjerne/src/Kjerne.php` | Bivrosts ferdige sider for installasjon finnes ikke ennå (fase 3). Ark-Ivars grensesnitt viser installasjonen selv, og må derfor kunne lastes før databasen finnes. |
| `Kjerne::forsok()` er offentlig | `kjerne/src/Kjerne.php` | Appens innlogging og registrering bruker den samme begrensningen av forsøk som nødinnloggingen. |
| Standardrepoet for oppdatering av Kjerne er `magnehg/ark-ivar`, ikke `magnehg/bivrost` | `kjerne/src/Kjerne.php` | En installasjon av Ark-Ivar skal aldri hente noe fra Bivrost-repoet. |
| Test for metoden `app` | `kjerne/tester/Enhet/AppbrukereTest.php` | |

## Hentet fra Gerð

Bivrosts felles komponenter ligger foreløpig i Gerð-prototypen (`magnehg/gerd`, grenen `prototype/v1`, commit
`59e1412b0b54e89af075ab572d980b37fa4afc0a`) til de flyttes til designpakken. Derfra er disse hentet til `web/src/`:

| Fil i Ark-Ivar | Fra Gerð | Endret |
|---|---|---|
| `komponenter/Tabell.tsx` | `prototype/web/src/bivrost/Tabell.tsx` | Tekstnøklene har prefikset `arkivar.`; én type er skrevet om for språkkontrollen |
| `komponenter/Skall.tsx` | `prototype/web/src/komponenter/Skall.tsx` | Ark-Ivars menyer; fargene fra designpakkens variabler (`--bivrost-farge-toppfelt-…`); språkvalg i brukermenyen |
| `komponenter/Felles.tsx`, `sider/Konto.tsx` | `komponenter/Felles.tsx`, `bivrost/Logginn.tsx` | Mønsteret (sidehode, kort, lasting, innloggingsboksen), skrevet for Ark-Ivars API |

## Det som ikke er tatt i bruk

- **Bivrost Sentral (OIDC):** koden ligger i kopien, men er av så lenge `BIVROST_SENTRAL_URL` ikke er satt. Ark-Ivar
  setter den aldri.
- **Versjonspakker og signering:** `skript/lag-versjonspakke.sh` og oppdateringen fra GitHub er ikke satt opp for
  Ark-Ivar ennå (egen tag-serie og egen signeringsnøkkel). `kjerne/signeringsnokkel.offentlig` er Bivrosts nøkkel og
  må byttes før den første versjonspakken lages.

## Hente inn en rettelse fra Bivrost

Sammenlign med Bivrost på commiten over, legg rettelsen inn for hånd, og oppdater tabellen øverst med den nye
commiten når hele kopien er ført frem. Endringene i tabellen «Det Ark-Ivar har endret» må beholdes.
