# Ark-Ivar

Ark-Ivar er et øvingssystem for studenter i arkivfag: et generisk Noark-system for sak og arkiv i den fiktive
**Sirevåg kommune**. Studentene får eksempeldokumenter som skal registreres, journalføres og følges gjennom
saksgangen, i hvert sitt øvingsarkiv. Systemet lager ikke uttrekk og avleverer ikke.

## Status

| Del | Status |
|---|---|
| Grunnmur: Bivrost Kjerne og designpakken som kopi (se [docs/opphav.md](docs/opphav.md)) | På plass |
| Kontoer: registrering med godkjent e-postdomene eller klassekode, bekreftelse på e-post, innlogging, glemt passord | På plass (API og tester) |
| Klasser (lærer) og administrasjon av brukere og godkjente domener | På plass (API og tester) |
| Nettgrensesnittet (React og Fluent UI v9 på designpakken): innlogging, registrering, min side, klasser, brukere, domener, installasjon | På plass |
| Arkivet til Sirevåg kommune: arkivstruktur, saksmapper, journalposter, postmottak med eksempeldokumenter | Ikke begynt |
| Offentlig journal og innsynskrav | Ikke begynt |
| Fasit og tilbakemelding til studenten, lærerens oversikt | Ikke begynt |

## Hvem bruker det

- **Student:** øver i sitt eget arkiv. Registrerer seg selv med e-postadresse fra et godkjent lærested, eller med en
  klassekode fra læreren. E-postadressen bekreftes med en kode.
- **Lærer:** oppretter klasser, deler klassekoden og ser studentene i klassen.
- **Administrator:** styrer brukere, roller og de godkjente e-postdomenene.
- **Drift:** installerer og oppdaterer (Kjernens nødinnlogging med passord og TOTP). Drift lager den første
  administratoren.

## Mappene

| Mappe | Innhold |
|---|---|
| `arkivar/` | Appen: `bivrost.php` (oppsettet for Kjerne), `public/` (webroten), `src/` (PHP, `ArkIvar\`), `migrasjoner/`, `i18n/`, `tester/` |
| `web/` | Nettgrensesnittet (React, Fluent UI v9, Vite). Bygges til `arkivar/public/app/`, som ikke ligger i git |
| `kjerne/` | Kopi av Bivrost Kjerne (`Bivrost\Kjerne\`): installasjon, økter, tilgang, migreringer, sikkerhetskopi, logg, språk |
| `grensesnitt/` | Kopi av Bivrosts designpakke: tokens, temaer, stilfiler, skriften Gerd Sans, ikoner |
| `skript/` | Bygg av Kjernens avhengigheter med eget navnerom (`prefiks.sh`) og versjonspakken |
| `docs/` | Opphavet til kopien (`opphav.md`) [Arkivplan for Sirevåg kommune](docs/arkivplan/README.md) og prosessbeskrivelsene, som er rutinedelen i planen ([docs/prosesser](docs/prosesser/README.md)). BPMN-figurene lages i [docs/figurer](docs/figurer/LES-MEG.md) |

Driftsmiljøet er et vanlig webhotell: PHP 8.2+, MySQL 8 eller MariaDB 10.6+, Apache og HTTPS. Serveren trenger
verken Composer eller Node.

## Utvikling

```
cd kjerne && composer install
bash ../skript/prefiks.sh        # Kjernens avhengigheter med prefikset Bivrost\Vendor\ i vendor-prefikset/
```

Grensesnittet:

```
cd web && npm ci --ignore-scripts
npm run build                    # typekontroll og bygg til arkivar/public/app/
npm run dev                      # utvikling; API-et sendes til PHP på port 8080
```

Lokal kjøring: lag en tom database, skriv `.env` i roten av repoet (`DB_*`, `BIVROST_ADRESSE="http://localhost:8080"`,
`BIVROST_TILLAT_HTTP_LOKALT="1"`, `ARKIVAR_EPOST_FIL="epost.jsonl"`), start
`php -S 127.0.0.1:8080 index.php` i `arkivar/public/` og åpne http://localhost:8080. Installasjonen gjøres i
nettleseren; koden står i `drift/installasjonskode.txt`, og brevene (kodene) i `drift/epost.jsonl`.

Kontrollene (alle skal være grønne før en commit):

```
cd kjerne
vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/phpcs && php bin/sjekk-sprak.php
cd ../arkivar
../kjerne/vendor/bin/phpunit && ../kjerne/vendor/bin/phpstan analyse && ../kjerne/vendor/bin/phpcs && php bin/sjekk-sprak.php
cd ../web && npm run build
```

Testene mot databasen lager egne databaser `biv_test_*` gjennom MariaDB-socketen som root (eller
`BIVROST_TEST_DB_*`) og sletter dem etterpå. Appen bruker Kjernens utviklingsverktøy og har ingen egen `vendor/`.

## .env

Kjernens nøkler står i `kjerne/.env.eksempel` og `kjerne/DRIFT.md`. Ark-Ivar legger til (`arkivar/.env.eksempel`):

| Nøkkel | Betydning |
|---|---|
| `ARKIVAR_EPOST_FRA` | Avsenderadressen for e-post fra Ark-Ivar (bekreftelseskoder og koder for nytt passord). Påkrevd i drift. |
| `ARKIVAR_EPOST_FIL` | Bare for lokal utvikling: et filnavn i driftsmappen. Brevene skrives dit i stedet for å sendes. |

## Lisens

Alle rettigheter forbeholdt, se [LICENSE](LICENSE).
