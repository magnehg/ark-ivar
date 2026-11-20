# Driftsveiledning for Bivrost Kjerne

For den som installerer, oppdaterer og drifter en app som bruker Bivrost Kjerne (BIV-B-035 klasse a: følger med
versjonspakken). Skrevet etter koden i commit `9a9011b` (2026-11-13); avsnitt 2, 3, 4, 8 og 10 er oppdatert
2026-11-14 med rettelsene i `docs/overdragbarhet.md` punkt 12 i repoet. Det som ikke er prøvd eller kontrollert mot
koden, er merket **Ikke kontrollert**. Utviklerinformasjonen står i repoet (`docs/`), ikke her.

Ingen hemmeligheter står i denne filen. Hemmeligheter står bare i `.env` på serveren eller i GitHub-hemmeligheter
(BIV-SIK-03).

## 1. Hva som trengs

**Serveren** (kontrolleres av installasjonen, `Installasjon::forutsetninger()`):

| Krav | Kreves |
|---|---|
| PHP 8.2 eller nyere | ja |
| Utvidelsene `pdo_mysql`, `sodium`, `openssl`, `zip`, `curl`, `mbstring`, `zlib` | ja |
| `intl` (ellers brukes en erstatning) og Argon2id i `password_hash` (ellers bcrypt) | nei, anbefalt |
| Skrivetilgang til driftsmappen og til mappen `.env` ligger i | ja |
| Driftsmappen og `.env` utenfor webroten | ja (når appen oppgir `webrot`) |
| MySQL 8 eller MariaDB 10.6+ med en egen database eller en database med ledige prefikser | ja |
| HTTPS med gyldig sertifikat | ja (BIV-AUT-09, BIV-B-016) |

Webhotell uten SSH og cron er nok (BIV-PLT-02): installasjon, oppdatering og opprydding skjer i vanlige
forespørsler, og ingenting trenger kommandolinje, Composer eller Node på serveren.

**Mappene i en installasjon** (`docs/arkitektur.md` i repoet):

```
<rot>/
  .env                 innstillinger og hemmeligheter (0640, utenfor webroten)
  bivrost/kjerne/      denne pakken (bivrost-kjerne-vX.Y.Z), med vendor/ og ressurser/grensesnitt/
  <app>/               appen, f.eks. gerd/ med public/ som webrot
  drift/               installasjonskode, låsefiler, sikkerhetskopier/, oppdatering/, eksport/ (utenfor webroten)
  sprakpakker/         valgfrie språkpakker
```

Hver pakke eier mappen sin helt: en oppdatering fjerner filer i mappen som ikke er med i den nye versjonen. Legg aldri
egne filer i `bivrost/kjerne/`.

## 2. `.env`

Filen ligger i `<rot>/.env` (eller der AppKonfig `env` sier), med rettighetene 0640, aldri i git og aldri under
webroten. Én `NØKKEL=verdi` per linje; verdien kan stå i `"…"`; linjer som begynner med `#`, er kommentarer; en linje
med feil form gir en feil i stedet for å bli oversett (`Konfig/Env`). Installasjonen (`Installasjon::database()`) skriver
`DB_VERT`–`DB_PASSORD`, `BIVROST_ADRESSE`, `BIVROST_KRYPTERINGSNOKKEL` og `BIVROST_HMAC_NOKKEL`. De to nøklene beholdes
hvis de står i filen fra før; `DB_*` og `BIVROST_ADRESSE` overskrives. Andre nøkler i filen blir stående.

| Nøkkel | Påkrevd | Skrives av | Betydning |
|---|---|---|---|
| `DB_VERT`, `DB_PORT`, `DB_NAVN`, `DB_BRUKER`, `DB_PASSORD` | ja (`DB_NAVN`, `DB_BRUKER`) | installasjonen | Databasen. Standard vert `127.0.0.1`, port 3306. |
| `DB_SOCKET` | nei | for hånd | Unix-socket i stedet for vert og port. |
| `BIVROST_ADRESSE` | ja | installasjonen | Nettstedets adresse (`https://…` uten sti). Brukes i stedet for Host-hodet (BIV-SIK-06) og som mål når HTTP sendes videre til HTTPS. |
| `BIVROST_KRYPTERINGSNOKKEL` | ja | installasjonen | Krypterer TOTP-hemmelighetene og tilgangstokenet i økten (BIV-SIK-04). **Bytt den ikke, og ta vare på den i sikkerhetskopien av `.env`:** byttes den, kan TOTP-hemmelighetene til driftsbrukerne ikke leses. TOTP-steget svarer da 401 `totp_ulesbar`, og bare gjenopprettingskodene virker. Hver driftsbruker logger inn med en kode og setter ny TOTP (avsnitt 3, «Ny TOTP»). Har en driftsbruker ingen koder igjen, må den gamle nøkkelen legges tilbake. |
| `BIVROST_HMAC_NOKKEL` | ja | installasjonen | HMAC av brukernavn og IP-adresser i tabellen over forsøk. |
| `BIVROST_SENTRAL_URL`, `BIVROST_KLIENT_ID`, `BIVROST_KLIENT_HEMMELIGHET`, `BIVROST_TILBAKE_URL` | nei (fase 2) | for hånd | Innlogging via Bivrost Sentral (OIDC). Uten `BIVROST_SENTRAL_URL` er bare nødinnloggingen på. |
| `BIVROST_REPO` | nei | for hånd | GitHub-repoet Kjerne oppdateres fra. Standard `magnehg/bivrost` (se `docs/overdragelse.md` i repoet). |
| `BIVROST_GITHUB_TOKEN` | **ja når repoet er privat** | for hånd | Token med lesetilgang (avsnitt 4). |
| `BIVROST_OFFENTLIG_NOKKEL`, `<APP>_OFFENTLIG_NOKKEL` | se avsnitt 5 | for hånd | Utgivernøkkelen (offentlig Ed25519-nøkkel) for Kjerne og for appen. |
| `BIVROST_FORHANDSVERSJONER` | nei | for hånd | `1` tar med forhåndsversjoner (`-rc.1` o.l.) i oppdateringen. Standard av. |
| `BIVROST_SYSLOG`, `BIVROST_SYSLOG_PEN` | nei | for hånd | Videresending av loggen som syslog (RFC 5424): `udp://`, `tcp://` eller `tls://vert:port`, og virksomhetens IANA-nummer for strukturerte data (BIV-LOG-07). |
| `BIVROST_TILLAT_HTTP_LOKALT` | nei | for hånd | Bare for tester og lokal utvikling: `1` tillater HTTP når klient, server og vertsnavn er denne maskinen. Aldri i drift. |
| `GITHUB_API_URL` | nei | for hånd | Bare for tester (et etterlignet GitHub-API). Standard `https://api.github.com`. |

## 3. Installasjon

Kjernens egne sider for installasjon, oppdatering, sikkerhetskopier og logg kommer i fase 3 (BIV-DRF-13). Til da
installerer man gjennom JSON-rutene under, fra appens egen installasjonsside eller med et verktøy som `curl`.

1. **Legg ut filene:** pakk ut versjonspakken for Kjerne i `<rot>/` (den har `bivrost/kjerne/…`) og appens pakke ved
   siden av. Webroten (domenet) skal peke på appens `public/`-mappe og sende alle forespørsler til appens
   `index.php`, også `/bivrost/…`. Det oppsettet hører til appen.
   Pakken har også `bivrost/kjerne/CHANGELOG.md` (endringene i hver versjon), `LICENSE` og `SECURITY.md` (hvordan
   sårbarheter meldes).
2. **Hent installasjonskoden:** den første forespørselen til `/bivrost/api/installasjon` lager
   `drift/installasjonskode.txt` (0600). Les den med FTP eller filbehandleren hos webhotellet; det viser at du har
   tilgang til filene. Koden sendes i hodet `X-Bivrost-Installasjonskode` på hvert kall. Høyst 10 feil per
   IP-adresse på 15 minutter.
3. **Forutsetningene:** `GET /bivrost/api/installasjon` med koden gir `steg` og listen over forutsetninger.
4. **Databasen:** `POST /bivrost/api/installasjon/database` med JSON `{"vert", "port", "navn", "bruker", "passord",
   "adresse"}` (`port` er et tall, `adresse` er `https://vert` uten sti). Tilkoblingen prøves, og installasjonen
   stopper uten å endre noe hvis databasen har tabeller med installasjonens prefikser (`bivrost_` og appens). `.env`
   skrives med nye nøkler.
5. **Den første driftsbrukeren:** `POST /bivrost/api/installasjon/bruker` med `{"brukernavn", "navn", "tittel",
   "epost", "passord"}` (passord etter NIST SP 800-63B: minst 12 og høyst 64 tegn). Svaret har `otpauth` og
   `hemmelighet` til en TOTP-app. Ingenting lagres i databasen ennå; steget må fullføres innen 15 minutter.
6. **Fullfør:** `POST /bivrost/api/installasjon/fullfor` med `{"kode": "<TOTP-koden>"}`. Migreringene kjøres, brukeren
   og eiermarkøren lagres, og svaret har ti **gjenopprettingskoder som vises bare denne ene gangen**. Ta vare på dem
   utenfor serveren. Feiler noe, slettes bare tabellene denne kjøringen laget.

Etterpå svarer installasjonsrutene 404, og `drift/installert.json` er låsefilen.

**Ny TOTP** (driftsbrukeren har mistet autentiseringsappen, eller `BIVROST_KRYPTERINGSNOKKEL` er byttet): logg inn med
passordet og en gjenopprettingskode (`POST /bivrost/api/innlogging/lokal/totp` med koden i `kode`; svaret har
`totp_lesbar`). Deretter:

1. `POST /bivrost/api/meg/totp` med `{"passord": "…"}` gir `otpauth` og `hemmelighet` til autentiseringsappen. Den nye
   venter kryptert i økten i 5 minutter; den gamle virker til den nye er bekreftet.
2. `POST /bivrost/api/meg/totp/bekreft` med `{"kode": "<koden fra appen>"}` lagrer den nye. Alle andre økter for
   brukeren avsluttes, og svaret har ti **nye gjenopprettingskoder** (vises én gang; de gamle slutter å virke) og den
   nye CSRF-nøkkelen. Uten steg 1, eller etter 5 minutter, svarer den 409 `totp_ikke_startet`.

Hendelsen `innlogging:totp_byttet` står i sikkerhetsloggen. Bare brukeren selv kan bytte sin TOTP.

**Apache:** driftsmappene får en `.htaccess` med `Require all denied` og en `web.config` (`Sikkerhetskopi/Mappevern`),
som et ekstra lag i tilfelle webroten er satt opp feil. Det egentlige vernet er at de ligger utenfor webroten.

**IIS:** **Ikke kontrollert.** Driftsmappene får en `web.config` som nekter alle, men det finnes ingen prøvd
veiledning for IIS ennå (BIV-PLT-06 er «Delvis»).

**Helsesjekk:** `GET /bivrost/helse` svarer `{"status": "ok"}` uten database og uten interne opplysninger
(BIV-DRF-11).

## 4. Privat repo: token for oppdatering

Repoet for Bivrost er privat fra 2026-11-13. GitHub svarer da `404` på listen over versjoner uten et token, og
oppdateringen stopper. Statuskoden fra GitHub vises overalt:

- `GET /bivrost/api/system/oppdatering` gir `plan_feil` = `bivrost.feil.oppdatering.github`, `plan_feil_verdier` =
  `{"status": 404}` og `plan_feil_tekst`, for eksempel «GitHub svarte ikke som ventet (HTTP 404): repoet finnes ikke,
  eller det er privat og tokenet mangler eller ikke har tilgang.»
- `POST /bivrost/api/system/oppdatering/steg` svarer 502 `github_feil` (RFC 9457) med den samme teksten.
- Maskin-API-et (`POST /bivrost/api/system/oppdatering/kjor`) svarer 502 med `{"kode": "github_feil",
  "github_status": 404, …}`.
- Hendelsen `oppdatering:feil` har `steg` = `plan` (kategorien sikkerhet, så en jobb som spør hver time, ikke fyller
  revisjonsloggen) eller `hent` (en fil i versjonen), og hele teksten i `feil_tekst`.

| Status | Betyr |
|---|---|
| 401 | Tokenet er ugyldig eller utløpt. |
| 403 | Tokenet har ikke tilgang til repoet, eller GitHubs grense for antall kall er nådd. |
| 404 | Repoet finnes ikke (`BIVROST_REPO`, appens `pakke.repo`), eller det er privat og tokenet mangler eller ikke har tilgang. |

- **Sett `BIVROST_GITHUB_TOKEN`** i `<rot>/.env` for hånd, med FTP eller filbehandleren. Installasjonen skriver den
  ikke, men beholder den når den står i filen fra før.
- **Ett token for alle pakkene:** Kjerne bruker det samme tokenet både for Kjerne (`BIVROST_REPO`) og for appens repo
  (AppKonfig `pakke.repo`) (`Kjerne::oppdaterer()`, `Oppdatering/GithubUtgivelser`). Er begge private, trenger tokenet
  lesetilgang til begge.
- **Minst mulig tilgang:** bruk et finkornet token (fine-grained personal access token) eller et token fra en GitHub
  App, begrenset til de repoene det gjelder, med bare *Contents: Read-only*. **Ikke kontrollert mot GitHubs
  dokumentasjon** i dag: at *Contents: Read-only* er nok for å liste versjoner og laste ned filene. Prøv med
  oppdateringsstatusen etter at tokenet er satt.
- **Tokenet sendes bare til GitHubs API** (`Authorization: Bearer …`) og aldri videre når nedlastingen sendes til en
  annen vert (`Http/UtgaendeHttp`). Det står aldri i loggen, i svar eller i pakken.
- **Utløp:** sett en utløpsdato og en påminnelse. Et utløpt token gir 401. Bytt verdien i `.env`;
  ingenting annet må gjøres.
- **Appene:** hver app som oppdateres gjennom Kjerne, må si i sin egen driftsveiledning at `BIVROST_GITHUB_TOKEN` må
  settes, og hvilke repoer tokenet må lese.

## 5. Signerte versjoner og utgivernøkkelen

Versjonspakkene signeres med Ed25519 (BIV-DRF-07). Utgiverens offentlige nøkkel følger med pakken:
`bivrost/kjerne/signeringsnokkel.offentlig` for Kjerne og `<mappe>signeringsnokkel.offentlig` for appen.

**Signaturen er obligatorisk med tillit ved første bruk** (TOFU, BIV-DRF-14, BIV-B-034, `Oppdatering/Utgivernokler`):

- **Klarering:** installasjonen lagrer nøkkelen i pakken som den klarerte, per pakke i `bivrost_innstilling`
  (`oppdatering.utgivernokkel.<pakke>`): ved installasjonen, for en installasjon fra før dette første gang den nye koden
  oppdaterer (nøkkelen i de installerte filene), eller ved den første oppdateringen som har en nøkkel. En pakke som har
  med seg den første nøkkelen, må være signert med den.
- **Deretter** avvises hver pakke som ikke er signert, eller som er signert med en annen nøkkel. Ingenting endres.
- **Bytte:** en pakke kan bare bytte den klarerte nøkkelen når den selv er signert med den klarerte, eller når den nye
  nøkkelen er satt i `.env` av driften (rot-README, «Bytte av utgivernøkkel»). Et bytte logges som `oppdatering:nokkel`
  med fingeravtrykket før og etter.
- **Bare fremover:** en tilbakerulling (avsnitt 8) beholder den klarerte nøkkelen slik den er. En nøkkel som er byttet
  ut, kanskje fordi den var lekket, blir aldri klarert igjen, og et signaturkrav faller aldri bort.
- **`.env`:** `BIVROST_OFFENTLIG_NOKKEL` for Kjerne og `<APP>_OFFENTLIG_NOKKEL` for appen (app-ID-en med store
  bokstaver og `_` for `-`, f.eks. `GERD_OFFENTLIG_NOKKEL`) trengs ikke lenger. Når nøkkelen er satt, går den foran den
  klarerte; den brukes ved et nødbytte (den gamle nøkkelen er tapt eller lekket). **Fjern linjen** etterpå og på
  servere som har den fra før: ellers må den byttes for hånd ved hvert bytte av nøkkel. En ugyldig nøkkel i `.env`
  stopper oppdateringen (den gir aldri «ingen nøkkel»).
- **Statusen** (`GET /bivrost/api/system/oppdatering`) viser per pakke `signatur_kreves` og `utgivernokkel`: kilden
  (`env`, `klarert`, `installert` = klareres ved neste oppdatering, eller ingen), fingeravtrykket (`SHA256:…`) og
  feilen når nøkkelen ikke kan leses.

Den hemmelige nøkkelen finnes aldri på serverne; den ligger bare som hemmeligheten `BIVROST_SIGNERINGSNOKKEL` i
miljøet `utgivelse` i GitHub hos utgiveren.

## 6. Oppdatering

Oppdateringen (BIV-DRF-05–08, `Oppdatering/Oppdaterer`) henter de nyeste versjonene av Kjerne og appen som passer
sammen (semver-krav i `versjon.json` og PHP-versjonen), og gjør ett steg per kall, så hvert kall holder seg under 30 s:

1. `hentet` – én pakke per kall: nedlasting, sjekksum, signatur, `versjon.json`, manifest, utpakking i
   `drift/oppdatering/`
2. `filer` – gjenopprettingspunkt (database og filer), så legges alle pakkene inn samlet; ved feil legges filene
   tilbake fra kopien
3. `database` – migreringene (Kjerne først), kjørt av den nye koden, med en ny databasekopi under låsen
4. `ingenting` – ingenting mer å gjøre

Eldre versjoner legges aldri inn. Gå tilbake med en tilbakerulling (avsnitt 8).

**Mellomstopp** (BIV-DRF-16): en versjon kan kreve at installasjonen har en bestemt versjon fra før
(`oppdateres_fra` i `versjon.json`, alltid som `>=X.Y.Z`). Ligger installasjonen lavere, også på en forhåndsversjon av
den versjonen (`1.4.0-rc.1` er lavere enn `1.4.0`), legges den nyeste versjonen den kan nå, inn først. Fortsett å
kjøre steg til resultatet er `ingenting`, så går oppdateringen videre av seg selv: `hentet` og de neste stegene
kommer igjen for hvert stopp. I statusen har hver pakke i `plan` feltet `mellomstopp: true` når pakken har en nyere
versjon enn den som legges inn; da kan flere oppdateringer følge. `uten_vei` lister pakker som har nyere versjoner,
men ingen blant de 30 laveste som den installerte versjonen kan oppdateres til; da må en eldre versjon legges inn
for hånd (kontakt utgiveren). Hvert stopp lager sitt eget gjenopprettingspunkt (avsnitt 8). Mellomstopp følges fra Kjerne 0.2.0; en
installasjon med 0.1.0 kjenner ikke feltet og går rett til den nyeste versjonen som passer.

**Som driftsbruker** (innlogget med tilgangen drift, med CSRF-hodet for endringer):
`GET /bivrost/api/system/oppdatering` viser status og plan, og `POST /bivrost/api/system/oppdatering/steg` kjører neste
steg. Gjenta til resultatet er `ingenting`.

**Fra en maskin** (f.eks. GitHub Actions etter en ny versjon, BIV-DRF-10): lag en nøkkel med
`POST /bivrost/api/system/oppdatering/nokkel` (`{"navn": "…"}`, som driftsbruker). Nøkkelen vises én gang; bare
SHA-256 lagres. Deretter `GET /bivrost/api/system/oppdatering/status` og
`POST /bivrost/api/system/oppdatering/kjor` med `Authorization: Bearer <nøkkel>`. Uten gyldig nøkkel svarer rutene 404.
`DELETE /bivrost/api/system/oppdatering/nokkel` trekker nøkkelen tilbake. Det finnes én nøkkel om gangen.

**Hva som starter oppdateringen** (kontrollert i koden 2026-11-14): ingenting av seg selv. Kjerne ser aldri etter nye
versjoner i vanlige forespørsler. Oppryddingen som skjer omtrent hver hundrede forespørsel (`Kjerne::husarbeid()`),
sletter bare gamle forsøk, økter, sikkerhetslogg og brukte JTI-er. En oppdatering kommer bare inn når en driftsbruker kjører
stegene, eller når en planlagt jobb utenfor webhotellet kaller maskin-API-et. Repoet har ingen slik jobb, så hver
installasjon må ha sin egen, eller oppdateres for hånd. Det faste oppsettet er GitHub Actions i et privat driftsrepo,
med manuell oppdatering som reserve (BIV-B-040). Malen for driftsrepoet ligger i Bivrost-repoet under `drift-mal/`
(følger ikke med pakken): én jobb per installasjon, hver natt og for hånd, med oppdateringsnøkkelen som hemmelighet.
Sjekk statusen jevnlig: `plan` viser om det finnes en nyere versjon.

**Når oppdateringen feiler**, står feilen i loggen som `oppdatering:feil`, med `steg` og Bivrosts feiltekst i
`feil_tekst`. Den nøyaktige feilmeldingen fra databasen eller PHP står i PHP-feilloggen, merket med korrelasjons-ID-en
fra svaret. Steget svarer etter RFC 9457: 502 `github_feil` når GitHub svarer feil, 409 `oppdatering_pagar` når en
annen kjøring pågår, ellers 500 `intern_feil`. Maskin-API-et svarer med de samme statuskodene og `kode`
(`github_feil` med `github_status`, `oppdatering_pagar`, `oppdatering_holdt_igjen` etter en tilbakerulling, eller
`oppdatering_feilet` der steget svarer `intern_feil`).

| `steg` i loggen | Hva som er endret | Hva som skjer videre |
|---|---|---|
| `plan` (listen over versjoner) | Ingenting. | Neste kall prøver på nytt. |
| `hent` (nedlastingen; et vellykket svar har `resultat` = `hentet`) | Ingenting i installasjonen. Arbeidsmappen ryddes. | Neste kall lager en ny plan og begynner på nytt. |
| `kopi` (gjenopprettingspunktet før filene) | Ingenting. Databasekopien eller filkopien kunne ikke lages. | Neste kall lager en ny plan og begynner på nytt. Sjekk plassen og skriveretten i driftsmappen. |
| `filer` | Feiler selve innleggingen, legges filene tilbake fra filkopien, og gjenopprettingspunktet er laget. | Neste kall begynner på nytt. Kunne filene ikke legges tilbake, sier feilen hvilket gjenopprettingspunkt som gjelder: gå tilbake til det (avsnitt 8). |
| (ingen hendelse) | Kan filene ikke skrives, eller er arbeidsmappen endret etter kontrollen, stopper filsteget før kopien. Ingenting er endret, og ingen `oppdatering:feil` logges. | Tilstanden blir stående på `filer`, og neste kall prøver steget på nytt. Rett skriveretten, eller slett `drift/oppdatering/tilstand.json` og `drift/oppdatering/klar/` (avsnitt 10). |
| `database` | De nye filene er lagt inn. Migreringene som ble kjørt før feilen, blir stående (MySQL og MariaDB kan ikke rulle tilbake endringer i tabellene). Kom en migrering i gang, ble det tatt en databasekopi rett før den første (`db_kopi` i hendelsen). Stoppet steget før det (en annen migrering pågår, eller migreringsfilene er i ustand), er `db_kopi` tom og databasen uendret. | Tilstanden blir stående på `database`, og neste kall prøver migreringene på nytt. Rett feilen, eller gå tilbake til gjenopprettingspunktet fra steget `filer` (avsnitt 8). |

Etter en tilbakerulling holdes maskin-API-et igjen (409 `oppdatering_holdt_igjen`) til en driftsbruker slipper det;
driftsbrukeren kan fortsatt kjøre stegene for hånd.

## 7. Sikkerhetskopi

- **Automatisk:** før hver oppdatering lages et gjenopprettingspunkt: en databasekopi og en filkopi av pakkenes mapper,
  knyttet sammen i `punkt-….json` (BIV-DRF-08, BIV-DRF-09).
- **For hånd:** `POST /bivrost/api/system/sikkerhetskopier` (drift). `GET /bivrost/api/system/sikkerhetskopier` lister
  kopiene og punktene, og `GET /bivrost/api/system/sikkerhetskopier/{navn}` laster ned én (nedlastingen logges).
- **Hva som er med:** databasekopien (`db-….sql.gz`) har bare installasjonens egne tabeller (`bivrost_` og appens
  prefikser), som et konsistent øyeblikksbilde. Filkopien (`filer-….zip`) har pakkenes mapper (`bivrost/kjerne/` og
  appens). **`.env`, `drift/` og `sprakpakker/` er ikke med.** Ta vare på `.env` (og særlig
  `BIVROST_KRYPTERINGSNOKKEL`) for seg, på et trygt sted utenfor serveren.
- **Hvor:** `drift/sikkerhetskopier/` (utenfor webroten; databasekopien 0600, filkopien 0640). Kopiene er **ikke kryptert** (`docs/asvs.md`
  8.1). Last dem ned og oppbevar dem et annet sted; en kopi på samme server forsvinner med serveren.
- **Opprydding:** under oppdateringen slettes eldre kopier, men de ti nyeste av hvert slag og alle som er yngre enn 14
  dager, beholdes (`Sikkerhetskopi/Kopilager`).
- **Innlesing for hånd:** databasekopien er gzip-komprimert SQL (`DROP TABLE IF EXISTS`, `CREATE TABLE`, `INSERT`),
  der hver setning slutter med en kommentarlinje `-- bivrost:setning`. **Ikke prøvd:** at den kan leses inn med
  `gunzip -c db-….sql.gz | mysql …` eller phpMyAdmin. Bruk tilbakerullingen når det går.

## 8. Tilbakerulling

`POST /bivrost/api/system/tilbakerulling` med `{"punkt": "punkt-….json"}` (drift) legger filene og databasen tilbake
til punktet under oppdateringslåsen (BIV-DRF-08, `Oppdatering/Tilbakerulling`).

- Hendelsene etter punktet i `bivrost_logg` og i appens loggtabeller (AppKonfig `logg.tabeller`) legges inn igjen, så
  loggene overlever (BIV-LOG-05, BIV-B-008). En loggtabell som ble laget etter punktet, lages på nytt. Svaret og
  hendelsen `oppdatering:tilbakerullet` har antallet per tabell i `logg_per_tabell`. En app som ikke har ført opp
  loggtabellen sin i `logg.tabeller`, mister hendelsene etter punktet.
- **Den klarerte utgivernøkkelen beholdes** (avsnitt 5): et bytte av nøkkel går bare fremover. Hendelsen
  `oppdatering:tilbakerullet` har antallet i `utgivernokler_beholdt`. Etter en tilbakerulling over et bytte har de
  installerte filene den forrige nøkkelen i `signeringsnokkel.offentlig`, men det er den klarerte som gjelder.
- Etterpå er **automatisk oppdatering holdt igjen**, så maskin-API-et ikke legger inn den samme versjonen på nytt
  (`drift/oppdatering/holdt-igjen.json`; API-et svarer 409 `oppdatering_holdt_igjen`). Når feilen er rettet, slipper
  en driftsbruker den med `POST /bivrost/api/system/oppdatering/slipp`.

## 9. Eksport og logg

- `GET /bivrost/api/system/eksport` (drift) gir hele eksporten for appen som JSON (`bivrost-eksport` v1,
  `skjema/eksport.schema.json`): rolleregisteret, tilgangshistorikken, loggen og appens egne data (BIV-EKS-01).
- `GET /bivrost/api/system/logg` (drift eller brukeradministrator i appen) leser loggen med filter (BIV-LOG-06). Se
  `openapi.json` for parametrene og eksporten som JSON Lines eller CloudEvents.
- Sikkerhetsloggen slettes etter AppKonfig `logg.oppbevaring_dager` (standard 365, minst 30); revisjonsloggen aldri.

## 10. Feilsøking

**Korrelasjons-ID:** hvert svar har hodet `X-Bivrost-Korrelasjon`, og feilsvarene (RFC 9457) har den samme ID-en.
Uventede feil skrives i PHP-feilloggen på serveren som `Bivrost [<korrelasjon>] <klasse>: <tekstnøkkel>`, og
hendelsene i loggen har den i `korrelasjon`. Søk på ID-en.

| Symptom | Årsak og tiltak |
|---|---|
| 403 `krever_https` | Forespørselen kom over HTTP. Bivrost godtar bare HTTPS (BIV-B-016). Sjekk sertifikatet og at `BIVROST_ADRESSE` er `https://…`. |
| 503 `ikke_installert` | Installasjonen er ikke fullført (avsnitt 3). |
| «Installasjonskoden er feil» / 429 | Les koden i `drift/installasjonskode.txt` på nytt. Etter 10 feil fra samme IP-adresse: vent 15 minutter. |
| «Databasen har allerede tabeller med installasjonens prefikser» | Databasen har `bivrost_`- eller appens tabeller fra før. Bruk en tom database, eller fjern tabellene for hånd hvis de er rester av en mislykket installasjon du selv har laget. |
| «… mangler i .env» / «Linje N i .env har feil form» | Rett `.env` (avsnitt 2). |
| «GitHub svarte ikke som ventet (HTTP 401/403/404) …» i `plan_feil_tekst`, 502 `github_feil` fra `…/oppdatering/steg` eller `…/kjor`, eller `oppdatering:feil` med `steg` = `plan` eller `hent` | GitHub svarte ikke 200 på listen over versjoner eller på en fil (`GithubUtgivelser`). Statuskoden sier hva som er galt (tabellen i avsnitt 4): 401 ugyldig eller utløpt token, 403 ingen tilgang eller grensen for antall kall, 404 feil repo eller privat repo uten token med tilgang. Sjekk `BIVROST_GITHUB_TOKEN`, `BIVROST_REPO` og appens `pakke.repo`. |
| «GitHub svarte med en liste over versjoner som ikke kunne leses» | GitHub (eller `GITHUB_API_URL`) svarte 200, men ikke med en JSON-liste. Prøv igjen senere; sjekk `GITHUB_API_URL`. |
| «Signaturen til versjonspakken stemmer ikke med utgivernøkkelen installasjonen stoler på» / «Versjonspakken er ikke signert, og installasjonen krever signatur med utgivernøkkelen» | Pakken er ikke signert med nøkkelen i statusen (`utgivernokkel`, avsnitt 5). Legg ikke inn pakken; kontakt utgiveren. Står en gammel `BIVROST_OFFENTLIG_NOKKEL` (eller `<APP>_OFFENTLIG_NOKKEL`) i `.env`, går den foran den klarerte: fjern linjen hvis den er utdatert. |
| «Versjonspakken bytter utgivernøkkelen, men er ikke signert med nøkkelen installasjonen stoler på» | Pakken har en ny nøkkel og er signert med den, men ikke med den klarerte. Er byttet varslet av utgiveren (den gamle nøkkelen er tapt eller lekket), sett den nye nøkkelen i `.env` og oppdater, og fjern linjen etterpå (rot-README, «Bytte av utgivernøkkel»). Ellers: legg ikke inn pakken. |
| «Versjonspakken har en utgivernøkkel, men er ikke signert med den» | Den første nøkkelen klareres bare når pakken er signert med den. Kontakt utgiveren. |
| «Utgivernøkkelen for … i … er ugyldig» | Filen er ikke en Ed25519-nøkkel (én linje base64, 32 byte). `<tag>.zip:<sti>` er filen i en versjonspakke: kontakt utgiveren. En sti uten `.zip` er den installerte filen (under `<rot>/`), som klareres før hver oppdatering når ingen nøkkel er klarert: legg tilbake filen fra versjonen som er installert (`versjon.json`), eller slett den (da klareres nøkkelen i neste pakke). `klar/<pakke>/…` er arbeidsmappen i `drift/oppdatering/`, endret etter kontrollen (ingenting er lagt inn): slett `drift/oppdatering/tilstand.json` og mappen `drift/oppdatering/klar/`, og start oppdateringen på nytt. Det samme gjelder «Filene i arbeidsmappen er endret siden de ble kontrollert». |
| «Utgivernøkkelen installasjonen stoler på, kan ikke leses fra databasen» | Raden `oppdatering.utgivernokkel.<pakke>` i `bivrost_innstilling` er ødelagt. Siste utvei: slett raden (rot-README, «Bytte av utgivernøkkel»); da klareres nøkkelen i de installerte filene ved neste oppdatering. |
| «Den offentlige signaturnøkkelen i .env er ugyldig» | Rett eller fjern `BIVROST_OFFENTLIG_NOKKEL` (eller `<APP>_OFFENTLIG_NOKKEL`) i `.env`. |
| «Det finnes nyere versjoner, men ingen kombinasjon som virker sammen med denne serveren» | Appen og Kjerne krever versjoner som ikke passer sammen, eller PHP er for gammel. Se `plan_feil` og `nyere_ukompatible` i statusen. |
| «En oppdatering pågår allerede» (409) | En annen kjøring holder låsen `drift/oppdatering/las`. Vent. Pågående steg står i statusen (`pagaende`). |
| «Migreringsfilene for … er ikke i orden (endret:…)» | En migrering som er kjørt, er endret. Legg tilbake filen fra versjonen som er installert (en utlagt migrering endres aldri, BIV-DRF-04). |
| «Filene kunne ikke legges inn, og tilbakerullingen ble ikke fullført» | Gå tilbake til gjenopprettingspunktet som står i meldingen (avsnitt 8). |
| «Fikk ikke skrevet sikkerhetskopien (full disk eller kvote?)» | Frigjør plass, last ned og slett gamle kopier i `drift/sikkerhetskopier/`. |
| 409 `oppdatering_holdt_igjen` | Etter en tilbakerulling. Slipp med `POST /bivrost/api/system/oppdatering/slipp` når feilen er rettet. |
| Driftsbrukeren har mistet TOTP-appen | Gi en av gjenopprettingskodene i stedet for TOTP-koden (`POST /bivrost/api/innlogging/lokal/totp`). Hver kode virker én gang. Sett deretter ny TOTP (avsnitt 3, «Ny TOTP»). |
| 401 `totp_ulesbar` («TOTP-hemmeligheten kan ikke leses …») | `BIVROST_KRYPTERINGSNOKKEL` i `.env` er ikke den som krypterte TOTP-hemmeligheten (byttet eller skrevet over). Logg inn med en gjenopprettingskode og sett ny TOTP (avsnitt 3). Har ingen driftsbruker koder igjen: legg tilbake den gamle nøkkelen fra sikkerhetskopien av `.env`. |

**Siste utvei i phpMyAdmin** (når nettleseren ikke kommer videre): rør bare `bivrost_`-tabellene, ta en kopi av
databasen først, og skriv hva du gjorde. Slettes raden `rolleregister.hash` i `bivrost_innstilling`, synkroniseres
rolleregisteret på nytt. Endringer i `bivrost_logg` skal aldri gjøres (bare tillegg, BIV-LOG-05).
