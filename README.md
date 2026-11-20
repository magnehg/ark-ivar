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
| Øvingsarkivet: eget arkiv per bruker for Sirevåg kommune, med journal og nullstilling | På plass |
| Seks arkivdeler: sakarkiv, eiendomsarkiv, personalarkiv, elevarkiv, barnehagebarnarkiv og møtearkiv | På plass |
| Primærklassering per arkivdel: arkivnøkkel, gårds- og bruksnummer, fødselsdato og navn, eller utvalg. Klasseringen kan skjermes | På plass |
| Arkivnøkkel bygd på statens fellesnøkkel, med søk | På plass |
| Mapper med dokumenter som kan åpnes og leses (33 mapper med 148 dokumenter fra start) | På plass |
| Arbeidsgangen: søk etter mappen, opprett den fra en saksmal hvis den ikke finnes, registrer journalposter i mappen | På plass |
| Postmottaket: 24 eksempeldokumenter som vurderes, registreres og journalføres, med skjerming | På plass |
| Fasit og tilbakemelding per kriterium, og lærerens oversikt over fremdriften i klassen | På plass |
| Offentlig journal (visning med skjermede titler og navn) | På plass |
| Saksgang: svar ut, avskrivning, avslutning av saker | Ikke begynt |
| Behandling av innsynskrav | Ikke begynt |
| Arkivstruktur som studenten selv kan endre | Ikke begynt |

## Det faglige innholdet

Arkivstrukturen og dokumentene ligger i `arkivar/data/`, og reglene for dem står i
[docs/innhold.md](docs/innhold.md):

- `sirevag.json`: arkivdelene, arkivnøkkelen, registrene (eiendommer, ansatte, elever, barnehagebarn og utvalg),
  enhetene, de ansatte, tilgangskodene med hjemmel, og mappene med dokumenter som ligger i arkivet fra start.
- `oppgaver.json`: dokumentene i postmottaket, hvert med fasit og forklaringer.

Arkivnøkkelen er oppdiktet for Sirevåg kommune og bygger på statens fellesnøkkel: klasse 0–2 følger fellesnøkkelen,
klasse 3–8 er kommunens fagområder. Den er ikke KS' K-koder (de er lisensbelagt). Alle personer, firmaer og lag er
oppdiktet. Kommunens ansatte, elevene og barnehagebarna har dyrenavn med bokstavrim (Gunnar Grevling, Frida Frosk,
Mathias Måke), og fødselsnumrene i registrene har feil kontrollsifre. Kommunevåpenet er også oppdiktet: i blått en gull
bjelke som på midten er bøyd ned i en nesten sluttet ring (en våg).

Regelverket i fasiten og dokumentene er kontrollert mot Lovdata 20. november 2026: arkivlova og arkivforskrifta som
gjelder fra 1. januar 2026 (Arkivverket heter nå Nasjonalarkivet), offentleglova, forvaltningsloven av 1967,
opplæringslova og barnehageloven. **Fasiten og forklaringene bør likevel leses av en som kan arkivfaget og
offentleglova, før systemet brukes i undervisning.** Det som særlig må følges med på, står nederst i
[docs/innhold.md](docs/innhold.md).

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
| `docs/` | Opphavet til kopien og senere dokumentasjon |

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
