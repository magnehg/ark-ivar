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
- Arkivet har 33 mapper med dokumenter fra start (brev, e-poster, skjemaer, notater, saksframlegg, protokoller,
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
- Reglene for innholdet står i `docs/innhold.md`.
