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
- **Ingen mobilversjon i første omgang** (brukerens valg 2026-11-20): bygg for PC-skjerm. Sidene skal ikke gå i stykker på smale skjermer, men bruk ikke tid på egne mobiloppsett eller mobiltester.
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
- **Øvingsarkivet** (`arkivar/src/Arkiv/`, rutene under `/api/arkiv/` i `Arkivruter`): hver bruker har ett arkiv
  (`arkivar_arkiv`, laget første gang det åpnes av `Ovingsarkiv::forBruker()` og fylt fra grunndataene), med
  mapper (`arkivar_sak`) og journalposter (`arkivar_journalpost`, `Register`). Arkivets ID kommer aldri fra
  nettleseren, og alle oppslag tar den med. Arkivstrukturen (`Grunndata`, `arkivar/data/sirevag.json`) er felles og
  kan ikke endres av studentene. Kodene følger Noark 5 (`Kodelister`); navnene står i språkfilene med små bokstaver
  (`arkivar.jptype.i`).
- **Statuskodene** (`Kodelister`) er hele kodelisten fra Noark 5 tjenestegrensesnitt (kontrollert 20.11.2026):
  saksstatus (B, A, U, R, S, P, F), journalstatus (J, F, G, E, A, U, M, S, R), dokumentstatus (B, F) og variantformat
  (P, A, O). Navnet og forklaringen («.bruk») står i språkfilene (`arkivar.saksstatus.`, `arkivar.jpstatus.`,
  `arkivar.dokstatus.`, `arkivar.variantformat.`); `KodelisterTest` krever begge på alle språk, og `useKode()` viser
  «J – Journalført». Dokumentfilen (`arkivar_journalpost.dokumentstatus` og `variantformat`, NULL uten dokument) er
  ferdigstilt, og i arkivformat når journalposten har status A (`Kodelister::variantformat()`).
- **Kommunevåpenet** (`web/src/bilder/sirevag-kommunevapen.svg`, `Kommunevapen`) er oppdiktet: «I blått en gull bjelke som på
  midten er bøyd ned i en nesten sluttet ring (en våg)». Det er valgt blant tre forslag og sjekket mot virkelige
  kommunevåpen på Jæren og sørvestkysten (ikke uttømmende). Det er et bilde med sine egne heraldiske farger og
  følger ikke fargetemaet. Det samme gjelder det skannede arket (`Ark`, inngående brev og skjemaer på papir).
- **Arkivdelene** har hver sin ordning (`Grunndata::ORDNINGER`), som sier hva primærklasseringen er: `emne`
  (sakarkivet, en klasse i arkivnøkkelen), `eiendom` (gårds- og bruksnummer), `person` (fødselsdato og navn,
  «14.03.2016 Måke, Mathias»: personal-, elev- og barnehagebarnarkivet) og `utvalg` (møtearkivet).
  `arkivar_sak.klasse` er koden i ordningen, og `Grunndata::harKlasse($arkivdel, $kode)` avgjør om den kan brukes: i
  sakarkivet en klasse på laveste nivå uten `henvisning`, ellers en post i arkivdelens register (`registre` i
  `sirevag.json`). `arkivar_sak.skjerm_klasse` skjuler klasseringen i den offentlige journalen (alltid i
  personarkivene). Arkivnøkkelen bygger på statens fellesnøkkel (klasse 0–2) med kommunens fagområder i 3–8.
  Personene i registrene har også et oppdiktet fødselsnummer med feil kontrollsifre; `GrunndataTest` feiler hvis
  ett består kontrollen.
- **Navnene:** kommunens ansatte, elevene og barnehagebarna har dyrenavn med bokstavrim (Gunnar Grevling, Mathias
  Måke); andre har vanlige navn. Navnene kan være vanlige ord, så kontrollen av om en tittel røper et navn
  (`Vurdering::harOrd`) sammenlikner ord for ord, ikke delstrenger.
- **Mappene som følger med** står i `saker` i `sirevag.json`, hver med journalposter som alle har et dokument
  (`startdokument` «<nøkkel>#<nr>» på journalposten; `GET /api/arkiv/journalposter/{id}` gir dokumentet).
  `Ovingsarkiv::fyll()` gir saksnummer etter saksdatoen og løpenummer etter journaldatoen. I dokumenttekstene står
  `{{sak}}` og `{{sak:<nøkkel>}}` for saksnummeret i studentens arkiv (`Grunndata::medSaksnumre()`).
- **Arbeidsgangen** er som i et sak- og arkivsystem: studenten søker etter mappen (`GET /api/arkiv/sok`,
  `Register::sok()`), oppretter den hvis den ikke finnes (`POST /api/arkiv/saker`, `Mapper`, gjerne fra en saksmal:
  `saksmaler` i `sirevag.json` med arkivdel, forslag til tittel med hakeparenteser, og søketips), og registrerer så
  journalposter i mappen (`POST /api/arkiv/saker/{id}/journalposter`, `Postmottak::journalpost()`). En mappe kan stå
  tom og få flere journalposter. Uten `oppgave` er journalposten studentens egen: den vurderes ikke og kan slettes.
  Mappene og dokumentene som fulgte med (`nokkel`, `startdokument`), kan ikke slettes. Grensesnittet tar med
  dokumentet som registreres, i adressen (`?dokument=…&handling=…`, `lesFlyt()` i `web/src/arkiv.ts`).
- **Postmottaket** (`Postmottak`, `Oppgavesett`, `arkivar/data/oppgaver.json`): studenten er arkivtjenesten og
  behandler eksempeldokumentene. Et dokument som ikke skal i arkivet, får bare valget registrert
  (`POST /api/arkiv/postmottak/{id}`); et som skal i arkivet, registreres som journalpost i en mappe. Svaret vurderes
  av `Vurdering` (ren funksjon, `VurderingTest`) og lagres i `arkivar_oppgavestatus`.
  **Fasiten sendes aldri til nettleseren før dokumentet er behandlet** (testen sjekker det). Skjerming krever en
  tilgangskode (hjemmel), og gjelder journalpostens tittel, mappens tittel og klassering, og avsenderen; den
  offentlige journalen (`Register::visJournalpost(…, true)`) viser de offentlige titlene og `*****`, og hjemmelen
  for skjermingen på mappen (`sak_hjemmel`) også når journalposten selv er åpen.
- **Vurderingen:** svaret bygges fra mappen og journalposten i databasen (`Postmottak::svar()`), ikke fra skjemaet.
  En ny sak skal ha sin egen mappe (`sak_alene`): ligger det dokumenter fra flere saker i en mappe studenten har
  opprettet, er mappen feil for alle. Derfor vurderes de andre dokumentene i mappen på nytt når et dokument
  registreres eller angres (`Postmottak::revurder()`). Skjerming vurderes begge veier: det som røper et navn, skal
  skjermes, og det som ikke røper noe, skal stå åpent (`skjerm_tittel`, `skjerm_sakstittel`). Klasseringen på en
  mappe studenten har opprettet, skal skjermes i personarkivene og ellers ikke, vurdert etter arkivdelen studenten
  valgte (`skjerm_klasse`). Avsenderen sammenliknes ord for ord uten hensyn til rekkefølge, småord og selskapsform
  (`Vurdering::sammeNavn`): alle ordene i navnet må være med, og fasiten kan ha kortformer i `part` som godtas
  alene («Statsforvalteren», «FAU», én av to foresatte). Vurderingen har ingen tekst selv: forklaringene kommer fra
  fasiten, og grensesnittet har reservetekster (`arkivar.vurdering.hjelp.*`). Forklaringen om skjerming er skrevet
  for fasitens arkivdel, så `skjerm_klasse` får den bare når studenten har valgt samme arkivdel.
- **Samtidighet og dato:** alt som registrerer, angrer eller sletter i et arkiv, går i en transaksjon som først
  låser arkivet (`Register::laas()`), så sjekkene (dokumentet er ubehandlet, mappen finnes og er åpen) fortsatt
  gjelder når raden lagres, og saks- og dokumentnumre ikke kan bli like (`ArkivTest` holder låsen fra en annen
  forbindelse og krever at hver operasjon venter). Nullstillingene går i tillegg etter hverandre på tvers av
  arkivene (`Db::las`), fordi sletting og påfylling i naboarkiver ellers kan vranglåse. Saksdato, journaldato og
  «ikke frem i tid» følger norsk tid (`Dato::idag()`), ikke UTC-klokka i Kjerne.
- **Henting i grensesnittet:** `useHent(sti)` gir bare data og feil for adressen som er gitt nå. Byttes adressen
  (også til ingen), glemmes svaret: det gamle vises aldri under en ny adresse, og en feil fra forrige gang gjelder
  ikke en ny henting. `last()` henter samme adresse på nytt, og da står det som vises, til svaret er der; en side som
  har data, må derfor vise `feil` selv (`Hentefeil` med «Prøv igjen»). `useStruktur()` gir `{ data, feil, last }` og
  husker strukturen til siden lastes på nytt; `glemStruktur()` kalles etter en nullstilling og når arkivet er
  utdatert. ID-er fra adressen kodes med `del()` før de settes i en sti til API-et, og `lesFlyt()` godtar bare ID-er
  på formen til dokumentene i postmottaket.
- **Språkmerking:** `<html lang>` er språket i grensesnittet og settes av `Sprak` (`web/src/i18n.tsx`), ikke av
  utseendet i `TemaTilbyder`. Innholdet fra arkivet (titler, navn, dokumenttekst, registerposter, saksmaler, hjemler)
  er norsk og merkes med `lang="nb"` der det står: `sprak: 'nb'` på en kolonne (`Kolonne` i `Tabell.tsx`),
  `kroppSprak="nb"` når hele tabellkroppen er fra arkivet, `tittelSprak="nb"` på `Sidehode`, `tipsSprak` i
  `Mappesok`. Tekst fra `t()` skal aldri ligge under et `lang="nb"` uten selv å være merket med språket i
  grensesnittet (`useSprak()`), og en tekstnøkkel skal ikke ha verdien fra arkivet som parameter: nøkkelen er
  ledeteksten («Tittel:»), og verdien står ved siden av i et merket element.
- **Migreringen `002_arkiv.sql`** er endret på stedet flere ganger før første utgivelse. Kjernen lagrer sjekksummen
  av hver kjørte fil og stopper på en endret fil, så en database installert fra en eldre commit må slettes og
  installeres på nytt. Fra første utgivelse fryses filen, og endringer kommer i en ny fil (`003_….sql`).
- **Utgaven av innholdet:** `arkivar_arkiv.innhold` er et fingeravtrykk av `sirevag.json` og `oppgaver.json` da
  arkivet ble fylt. Er filene byttet siden, gir `GET /api/arkiv` `utdatert`, og forsiden ber studenten nullstille:
  dokumentene leses fra filene og kan ha endret seg under mappene i arkivet. En behandling av et dokument som siden
  er tatt ut av `oppgaver.json`, teller ikke i oversiktene (`Oppgavesett::ider()`), og journalposten kan slettes
  (`Postmottak::visJournalpost()` og `slettJournalpost()`).
- **Nytt innhold** (mappe, dokument, saksmal eller eksempeldokument i postmottaket): følg `docs/innhold.md`
  (regelverket per november 2026, praksisen i Sirevåg kommune, formatet og tittelreglene). `GrunndataTest` sjekker at
  kodene finnes, at dokumentene er hele, og at ingenting i personarkivene røper navn i offentlig journal, og
  `ArkivTest::testFasitenGirFullUttellingForAlleDokumentene` at fasiten kan oppnås gjennom API-et. Dokumentene er
  datert; dokumentdatoen kan ikke ligge frem i tid, så testene setter klokka til 20.11.2026. Navn som ikke må stå i
  en offentlig tittel, står i `sensitive_ord`. Innholdet i `arkivar/data/` er norsk og oversettes ikke. Hvert
  dokument skal ha en naturlig mappe: ingen samlesaker.
- **E-post** (`arkivar/src/Epost/`): `Epostsender` med `MailEpost` (drift) og `FilEpost` (tester og lokal utvikling).
- **Logg:** hendelser skrives med `$kjerne->logg()->skriv('urn:arkivar:hendelse:<område>:<handling>', …)`, og
  klarteksten står i språkfilene som `arkivar.hendelse.<område>.<handling>`.
- **Grensesnittet** (`web/`): React, Fluent UI v9 og react-router, bygget med Vite til `arkivar/public/app/`.
  `api.ts` (CSRF-nøkkelen tas vare på fra svarene), `i18n.tsx` (de samme språkfilene som serveren, `useT()`),
  `tema.tsx` (Fluent-temaet fra designtokenene, valgene i `localStorage` med designpakkens `preferanser.ts`),
  `komponenter/Skall.tsx` (menyene i `useModuser()`), `komponenter/Tabell.tsx` og sidene i `sider/`. Tekstnøkler har
  bare små bokstaver, tall, punktum og understrek. Språkkontrollen leser `web/src` også: en pil fulgt av en generisk
  type på samme linje (`=> Promise<…>`) blir lest som tekst, så skriv typen som `{ (): Promise<…> }`.
- **CSP:** Kjerne setter `style-src 'self'`. Fluent lager stilene i nettleseren, så `App::grensesnitt()` gir hvert
  svar en engangsverdi (nonce) i CSP-hodet og i `<meta name="arkivar-stil-nonce">`, som `tema.tsx` setter på
  stilelementene. Ingen innebygde skript eller stiler i `web/index.html`.
- **Tester:** `arkivar/tester/Stotte/AppTestCase` installerer appen i en ny database per test og gir nettlesere med
  egne informasjonskapsler (`Nettleser`).
