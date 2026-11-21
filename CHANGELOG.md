# Endringer i Ark-Ivar

## Ikke utgitt

- Grunnmuren: kopi av Bivrost Kjerne 0.2.0 og designpakken, uten kobling til Bivrost (docs/opphav.md).
- Kjerne-kopien kan ha appens egne brukere (`Appbrukere`, innloggingsmetoden `app`).
- Kontoer: registrering med e-postadresse fra et godkjent sted eller med klassekode, bekreftelse av adressen med
  en kode på e-post, innlogging, glemt passord og bytte av passord.
- Klasser for lærere, og administrasjon av brukere, roller og godkjente e-postdomener.
- Nettgrensesnittet på Bivrosts designpakke, med skallet og tabellen fra Gerð: innlogging, registrering,
  bekreftelse, glemt passord, driftsinnlogging, forsiden, min side, klasser, brukere og godkjente e-postdomener.
  Fem fargetemaer i lys og mørk modus, bredde og tre språk i brukermenyen.
- Installasjonen gjøres i grensesnittet: Kjerne-kopien slipper appens sider gjennom også før installasjonen.
- Siden får en engangsverdi for stilene i CSP-hodet, så Fluent virker uten `unsafe-inline`.
- Øvingsarkivet: hver bruker får et eget arkiv for Sirevåg kommune med saker og journal, og kan nullstille det.
  Arkivstrukturen (arkivdeler, arkivnøkkel, enheter, ansatte og tilgangskoder) kan leses.
- Postmottaket med eksempeldokumenter: studenten velger hva som skal skje med hvert dokument, og får en vurdering mot
  fasiten med forklaring per kriterium. Behandlingen kan angres og gjøres på nytt.
- Journalen kan vises som offentlig journal, med skjermede titler og navn.
- Læreren ser hvor mange dokumenter hver student i klassen har behandlet, og hvor mange valg som var riktige.
- Arkivet har seks arkivdeler med hver sin primærklassering: sakarkiv (arkivnøkkel), eiendomsarkiv (gårds- og
  bruksnummer), personalarkiv, elevarkiv og barnehagebarnarkiv (fødselsdato og navn) og møtearkiv (utvalg).
  Registrene (eiendommer, ansatte, elever, barnehagebarn og utvalg) står på siden Arkivstruktur.
- Ny arkivnøkkel bygd på statens fellesnøkkel: klasse 0–2 følger den siste utgaven av fellesnøkkelen, klasse 3–8 er
  kommunens fagområder. 302 klasser med merknader, henvisninger til de andre arkivdelene og søk.
- Arkivet har 134 mapper med dokumenter fra start (brev, e-poster, skjemaer, notater, saksframlegg, protokoller,
  vedtak og avtaler). Alle kan åpnes og leses. Dokumentene viser til saksnummeret mappen har i studentens arkiv.
- Ny arbeidsgang, som i et sak- og arkivsystem: studenten søker etter mappen, oppretter den hvis den ikke finnes, og
  registrerer så journalposter i mappen. En mappe kan stå tom og kan få flere journalposter, også studentens egne
  uten dokument fra postmottaket. Tomme mapper og egne journalposter kan slettes.
- Søk i mappene (tittel, klassering, saksnummer og dokumentene i mappen), med søketips.
- Saksmaler: arkivdelen og et forslag til tittel er fylt inn når mappen opprettes («Elevmappe – [etternavn,
  fornavn]»), og hver mal har et søketips. Forslaget må fylles ut før mappen kan opprettes.
- Klasseringen kan skjermes med en avkrysning. Den offentlige journalen viser da ***** i stedet, og vurderingen
  sjekker at klasseringen på en ny mappe i et personarkiv er skjermet.
- Vurderingen krever at en ny sak får sin egen mappe: en mappe med dokumenter fra flere saker er feil for alle
  dokumentene i den. Vurderingen følger med når et dokument legges til i mappen eller angres.
- Skjerming vurderes begge veier: en tittel eller klassering som ikke røper noe, skal stå åpent. Skjerming uten
  grunn gir avvik, både på mappen og på journalposten.
- Avsenderen sammenliknes ord for ord: «Due, Dina» er «Dina Due», og «Jærrent» er «Jærrent AS». Alle ordene i navnet
  må være med, og fasiten kan godta kortformer («Statsforvalteren», «FAU», én av to foresatte).
- En hel klasse kan nullstille øvingsarkivet samtidig: nullstillingene går etter hverandre.
- Den offentlige journalen viser hjemmelen for skjermingen på mappen også når journalposten selv er åpen.
- Søket tåler tegnsetting («Måke, Mathias»).
- To registreringer samtidig i samme arkiv (to faner, dobbel innsending) går etter hverandre: samme dokument kan
  ikke registreres to ganger, og numrene blir ikke like.
- Saksdato og journaldato følger norsk tid.
- Forsiden sier fra når mappene og dokumentene i Ark-Ivar er oppdatert etter at øvingsarkivet ble laget, og ber
  studenten nullstille.
- Feilmeldingen i skjemaene for ny mappe og ny journalpost står ved knappen, så den synes i et lavt vindu.
- Et dokument som alt er behandlet (Tilbake i nettleseren etter en registrering), gir en melding med lenke til
  vurderingen i stedet for en side som laster uten ende. Henting som feiler, gir en melding med «Prøv igjen».
- Innholdet fra arkivet er merket som norsk, og tekstene fra grensesnittet følger språket som er valgt. Knappene i
  søkefeltene og klassevelgeren har navn på alle tre språkene.
- Siden for en journalpost ligger under mappen i adressen, så «Mapper» er aktiv i menyen.
- Språket på siden følger alltid språket som er valgt, også ved første besøk og etter bytte av tema eller modus.
- Journalen beholder sorteringen når den byttes til offentlig journal.
- Kommunens ansatte, elevene og barnehagebarna har dyrenavn med bokstavrim, etter mønster av Pelle Parafins
  Bøljeband (Gunnar Grevling på byggesak, Stian Stokkand, Frida Frosk, Sandra Salamander).
- Postmottaket har 24 dokumenter. Hvert dokument har en naturlig mappe: en som finnes i arkivet, eller en ny for
  saken eller personen. Alle arkivdelene og alle fire behandlingsmåtene er med.
- Fasiten og dokumentene følger arkivlova og arkivforskrifta som gjelder fra 1. januar 2026, blant annet at
  innsynskrav ikke er omfattet av journalføringsplikten. Tilgangskodene har fått fullstendige hjemler.
- Mappene får saksnummer etter saksdatoen og journalpostene løpenummer etter journaldatoen. Listen over mapper kan
  avgrenses til én arkivdel og viser om mappen er avsluttet.
- Statuskodene fra Noark 5 for sak (saksstatus), journalpost (journalstatus) og dokumentfil (dokumentstatus og
  variantformat) er lagt inn. Hele kodelisten står på siden Arkivstruktur med forklaring av når hver kode brukes,
  og mapper, journalposter og dokumentfiler vises med kode og navn («J – Journalført»). Dokumentfilen er ferdigstilt
  (F) og ligger i arkivformat (A) når journalposten er arkivert, ellers i produksjonsformat (P).
- Sirevåg kommune har fått et oppdiktet kommunevåpen, som står i toppfeltet, i brevhodet på kommunens dokumenter og
  på forsiden.
- Inngående brev og skjemaer på papir vises som skannede ark: hvitt papir med et svakt gråskjær, litt korn, en smal
  skygge fra skanneren, litt skjev plassering og mottaksstempel. Teksten er fortsatt vanlig tekst. E-post og kommunens egne dokumenter er som før.
- Menyen under Arkivet har en lenke til arkivplanen, som ligger i undermappen `arkivplan/` ved siden av appen
  (`arkivar/public/arkivplan/index.html`).
- Reglene for innholdet står i `docs/innhold.md`.
- 100 nye saksmapper i sakarkivet med 105 dokumenter, skrevet om fra dokumentene i Paragrafkarusellen og tilpasset
  Sirevåg kommune (nøklene begynner med «k1-» til «k10-»).
- Kontrolloppgaver: en utvidbar undermeny med tellere for dokumenter til journalføring (inngående, interne og
  utgående) og saksmapper til kontroll og til avslutning. Oppgavene løses fra listene, fra journalposten eller fra
  mappen. Et dokument studenten ferdigstiller, står til journalføring.
- Arbeidsflyten etter registreringen:
  - Hver journalpost har en meny (Rediger, Svar, Avskriv, Flytt og det som ellers er lov i statusen), og én
    hovedknapp for det neste steget i saksgangen. Det som ikke kan angres, bekreftes først, og etterpå kommer en
    kvittering med «Angre» der det går an. Journalføringen kan angres samme dag.
  - Avskrivning og restanser: «Svar» lager et utgående dokument i samme mappe med valg av dokumentmal, mottaker og
    tittel fylt ut. Når svaret er ekspedert, avskrives det inngående med BU, og de to viser til hverandre. Et
    foreløpig svar avskriver ikke. «Avskriv» uten svar har avskrivningsmåtene fra Noark 5 og en merknad.
    Inngående dokumenter uten avskrivning merkes «Restanse, N dager», og «Restanser» er en ny liste under
    Kontrolloppgaver. Arkivet har ti restanser fra start; de andre inngående dokumentene er avskrevet.
  - «Flytt» flytter en journalpost til en annen åpen mappe: nytt dokumentnummer, samme løpenummer.
  - Registreringen kan rettes: alt før journalføringen, bare tittelen og skjermingen etterpå.
  - Mappene går R → B → S → A: «Kontroller», «Send til avslutning», «Avslutt mappen» og «Send tilbake til
    behandling». Sjekklisten før avslutning viser hva som mangler (ikke journalført, restanser, under redigering),
    med en knapp som løser hvert punkt, og serveren stopper avslutningen til listen er tom.
  - I en mappe står dokumentnummeret alene (1, 2, 3); hele nummeret står i listene på tvers av mapper.
  - Merknader på journalposter (tabellen `arkivar_merknad`, som også kan ha merknader på mapper).
  - Arkiver fylt før denne endringen regnes som utdaterte og må nullstilles (avskrivningene legges inn ved påfyllingen).
- Sensitive henvendelser (taushetsbelagte opplysninger, søknader på stilling og vitnemål) står ikke lenger som
  e-post: de er kommet i kommunens digitale skjema. SvarInn er lagt til som kanal.
- Skriveflate: studenten skriver egne dokumenter i en mappe fra en dokumentmal (utgående brev, foreløpig svar etter
  forvaltningsloven, internt notat, møtereferat og et blankt ark for inngående brev), lagrer versjoner og
  ferdigstiller dokumentet.
- Arkivdel er en meny på linjen med visningsikonene, telleren står på samme linje, søketipset ligger bak et
  spørsmålstegn, og arkivplanen står nederst i sidemenyen.
- Oversiktene (mapper, dokumentene i en mappe, journalen og søketreffene) kan vises i kolonner, med tittelen først
  eller på to linjer. Brukeren bytter med ikoner over oversikten, og valget lagres på kontoen.
- Kanten mellom siden og lesevinduet kan dras sidelengs, og bredden huskes i nettleseren. Lenkene i oversiktene står
  i fet skrift uten understreking.
- Arkivformatet står før produksjonsformatet. 14 utgående dokumenter har to versjoner, der versjon 1 er
  saksbehandlerens grove utkast.
- Mappeoversikten: mappeikon ved saksnummeret (åpen mappe under behandling, lukket når saken er avsluttet), saksnummer
  og tittel først, så enhet og saksansvarlig, og primærklasseringen sist med arkivdelen under. Søketreffene har samme ikon.
- Løpenummeret til journalpostene skrives med nummeret først og året sist («104/2026»).
- Dokumentfiler: hver journalpost har filen i produksjonsformat, og i arkivformat (PDF, eller ren tekst for e-post) når
  den er journalført, ekspedert eller arkivert. Dokumentene står med ikonet til arkivformatet foran tittelen. En
  utvidet journalpost viser hoveddokumentet og vedleggene, og hvert av dem kan utvides til versjoner og varianter. Radene i mappeoversikten, i en mappe og i journalen kan utvides og viser filene med ikon for
  formatet, og et klikk på en fil åpner den i et lesevindu til høyre.
- Tilgangskoder for alle unntakene i offentleglova §§ 13–26 (29 koder), og en samlemappe «Høringer 2026» (klasse 008)
  for høringer kommunen ikke uttaler seg til.
- Etter gjennomgangen av arbeidsflyten:
  - En avsluttet mappe er låst også mot sletting: en journalpost i den kan ikke slettes, og behandlingen av et
    dokument fra postmottaket som ligger i den, kan ikke angres. Et svar som avskriver et arkivert dokument, kan
    ikke tas ut av journalen, heller ikke når svaret ligger i en annen mappe.
  - Et dokument studenten har skrevet, kan slettes til det er ferdigstilt eller ekspedert (R, M og S). Etterpå
    rettes feil ved å angre journalføringen eller rette registreringen.
  - «Angre avskrivning» gjelder avskrivning uten svar. BU følger svaret og kan ikke angres for seg.
  - En journalpost som er arkivert uten journalføring i en åpen mappe, kan rettes og flyttes.
  - Avskrivningene fra start: et svar må gå til avsenderen, et foreløpig svar avskriver ikke, og høringene i
    samlemappen er tatt til etterretning. Arkivet har 44 BU, 7 SA, 15 TE og ti restanser (18–60 dager).
    Arkiver fylt før endringen er «utdatert» og må nullstilles.
  - «Flytt» velger mappen først og bekreftes etterpå, titlene i søket er ikke lenker, og kvitteringen har lenke
    til den nye mappen. Fokus går tilbake til knappen når en dialog lukkes, og til kvitteringen etter en handling.
  - Mappen viser dokumentene med tittelen som bred kolonne (avsender, dato og tilgangskode under) og hovedknappen
    i raden. Mappen har menyen «Mer», og en mappe til avslutning tar ikke imot nye dokumenter før den er sendt
    tilbake. Sjekklisten journalfører med bekreftelse og kvittering med «Angre».
  - Angres journalføringen av et dokument studenten har skrevet, får journalposten tilbake journaldatoen fra før.
