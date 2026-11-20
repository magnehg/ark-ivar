# CLAUDE.md – Ark-Ivar

Instruksjoner for den som bygger Ark-Ivar, også Claude-økter. Les `README.md` og `docs/opphav.md` først.

## Hva Ark-Ivar er
Et øvingssystem for studenter i arkivfag: et generisk Noark-system for den fiktive Sirevåg kommune. Studentene får
eksempeldokumenter som skal registreres og journalføres, hver i sitt eget øvingsarkiv. Systemet skal ikke lage
uttrekk eller avlevere.

## Faste regler
- **Språk:** skriv på norsk bokmål (kode, kommentarer, dokumentasjon, commits og samtalen). All tekst til brukere
  ligger i språkfilene (`arkivar/i18n/<språk>/arkivar.json`, ICU MessageFormat, nøkler med prefikset `arkivar.`) på
  bokmål, nynorsk og engelsk. Ingen brukertekst i koden. Kjør `php arkivar/bin/sjekk-sprak.php`.
- **Ikke koblet til Bivrost:** `kjerne/`, `grensesnitt/` og `skript/` er en kopi (se `docs/opphav.md`). Ark-Ivar
  logger aldri inn mot Bivrost Sentral og henter aldri noe fra Bivrost-repoet. Gjenbruk kopien så langt det går, og
  endre den bare når appen ikke kan løse det selv. Hver endring i kopien føres i tabellen i `docs/opphav.md`.
- **Kvalitet:** `declare(strict_types=1)`, PSR-12, PHPStan nivå max uten ignorering, og tester for alt nytt. Koden
  skal kunne leses uten muntlig forklaring: beskrivende norske navn, og en docblokk som sier hvorfor.
- **Kontrollene før hver commit** står i `README.md` («Utvikling»), for både `kjerne/` og `arkivar/`.
- **Ingen hemmeligheter** i kode, git, pakker, logg eller svar. De står i `.env` utenfor webroten. Testdata er aldri
  ekte personopplysninger: bruk fiktive navn og domener under `.example`.
- **Databasen:** appens tabeller har prefikset `arkivar_`. En ny migrering er en ny fil
  `arkivar/migrasjoner/NNN_navn.sql` (neste nummer, `IF NOT EXISTS`). En migrering som er lagt ut, endres aldri.
  Kjernens tabeller (`bivrost_`) endres ikke av appen.
- **Utseendet:** bruk tokenene fra designpakken, aldri faste farger.
- **Fiktivt innhold:** virksomheten er Sirevåg kommune. Personer, saker og dokumenter i eksempeldataene er oppdiktet
  og skal ikke kunne forveksles med virkelige personer eller saker.

## Arkitekturen kort
- `arkivar/public/index.php` lager `Kjerne` fra `arkivar/bivrost.php` og gir forespørslene til `ArkIvar\App::ruter()`.
  Kjerne tar HTTPS, økten, CSRF (hodet `X-CSRF`, nøkkelen fra `GET /bivrost/api/okt`), sikkerhetshodene, feilsvarene
  (RFC 9457) og sine egne ruter under `/bivrost/`. Appens ruter ligger under `/api/`.
- **Brukere** (`arkivar/src/Brukere/`): `Brukere` (tabellen `arkivar_bruker`, og `Appbrukere` for Kjerne), `Konto`
  (registrering, bekreftelse, innlogging, passord), `Domener` (godkjente e-postdomener), `Klasser`, `Engangskoder`
  og `Roller`. Rollene er et hierarki: student < lærer < administrator. Tilgang sjekkes med
  `$kjerne->krev(Roller::urn(Roller::LAERER))`.
- **E-post** (`arkivar/src/Epost/`): `Epostsender` med `MailEpost` (drift) og `FilEpost` (tester og lokal utvikling).
- **Logg:** hendelser skrives med `$kjerne->logg()->skriv('urn:arkivar:hendelse:<område>:<handling>', …)`, og
  klarteksten står i språkfilene som `arkivar.hendelse.<område>.<handling>`.
- **Tester:** `arkivar/tester/Stotte/AppTestCase` installerer appen i en ny database per test og gir nettlesere med
  egne informasjonskapsler (`Nettleser`).
