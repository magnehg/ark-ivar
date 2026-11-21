# Det faglige innholdet

Dette er reglene for innholdet i øvingsarkivet: mappene med dokumenter som ligger i arkivet fra start
(`arkivar/data/sirevag.json`), og dokumentene i postmottaket med fasit (`arkivar/data/oppgaver.json`). Følg dem når
innhold legges til eller endres. `GrunndataTest` kontrollerer det som kan kontrolleres maskinelt: at kodene finnes,
at dokumentene er hele, at fødselsnumrene er oppdiktet, og at ingenting i personarkivene røper navn i den offentlige
journalen.

Regelverket er kontrollert mot Lovdata 20. november 2026. **Fasiten og forklaringene bør likevel leses av en som kan
arkivfaget og offentleglova, før systemet brukes i undervisning.** Se «Det som må følges med på» nederst.

Dagens dato i fortellingen er fredag 20. november 2026. Ingenting er datert senere. (Testene setter klokka til den
datoen.)

## Kommunen

- Sirevåg kommune ligger på Jæren i Rogaland og har om lag 6 800 innbyggere. Rådhuset: Rådhusgata 1, 4399 Sirevåg.
  E-post: postmottak@sirevag.example. Nettsted: www.sirevag.example.
- Nabokommunen heter Kvassheim kommune (oppdiktet). Lokalavisen heter Jærposten (oppdiktet).
- Statlige og regionale organer brukes med de virkelige navnene: Statsforvalteren i Rogaland, Rogaland fylkeskommune,
  Statens vegvesen, Kartverket, Nav, Arbeidstilsynet, Mattilsynet, Nasjonalarkivet og KS. Private firmaer, lag og
  personer er oppdiktet. PP-tjenesten er interkommunal («PP-tjenesten for Sirevåg og Kvassheim»).
- Steder i kommunen: Sirevåg sentrum, havna, Fyrveien, Strandveien, Raunesvegen, Havnegata, Skolevegen, Stadionveien,
  Industrivegen, Bakkevegen. Postnummer 4399 Sirevåg.
- Skoler: Sirevåg skole (1.–7. trinn, Skolevegen 2), Sirevåg ungdomsskole (8.–10. trinn). Barnehager: Fyrlykta
  barnehage (avdelingene Fyret og Naustet), Tjørnhaugen barnehage (avdelingene Lyngen og Røsslyngen).
  Sirevåg omsorgssenter er sykehjemmet.

### Politisk ledelse

- Ordfører Gunnhild Søyland (Sp), varaordfører Torstein Ege (H).
- Plan- og teknisk utvalg: leder Arvid Kvinnesland (H). Utvalg for oppvekst og kultur: leder Mona Tunheim (Ap).
  Kontrollutvalget: leder Ragnhild Vigrestad (SV).
- Andre kommunestyrerepresentanter du kan bruke: Per Olav Skjæveland (Sp), Ingunn Haarr (KrF), Jostein Obrestad (Frp),
  Turid Malmin (Ap), Kjell Inge Madland (H).
- Møtesaker nummereres «PS 71/26» (løpende per utvalg og år). Møtene holdes i kommunestyresalen i rådhuset.

### Administrasjonen

Enheter og ansatte står i `sirevag.json` (enheter, ansatte). Saksansvarlig på en mappe er en av de ansatte der
(initialene), og den ansatte må høre til mappens enhet. Brev fra kommunen undertegnes av saksbehandleren med navn og
stilling. Kommunedirektøren er Ulla Ugle.

## Navnene

Kommunens ansatte, elevene og barnehagebarna har dyrenavn med bokstavrim: fornavnet og etternavnet begynner på samme
bokstav (Gunnar Grevling på byggesak, Stian Stokkand, Mathias Måke). Mønsteret er hentet fra Pelle Parafins
Bøljeband, der bandet blant annet har Guttorm Grevling, Frida Frosk og Sandra Salamander. Foresatte har samme
etternavn som barnet. Alle andre personer (innbyggere, politikere, søkere, journalister) har vanlige navn.
`GrunndataTest` sjekker bokstavrimet for alle i registrene. Navnene er korte og kan være vanlige ord, så kontrollen av
om en tittel røper et navn, sammenlikner ord for ord (også med genitivs-s).

## Arkivdelene, primærklasseringen og arkivnøkkelen

| Arkivdel | Primærklassering | En mappe er |
|---|---|---|
| Sakarkiv (SAK) | En klasse i arkivnøkkelen | En saksmappe per sak |
| Eiendomsarkiv (EIENDOM) | Gårds- og bruksnummer | En saksmappe per sak på eiendommen |
| Personalarkiv (PERSONAL) | Fødselsdato og navn | En personalmappe per ansatt |
| Elevarkiv (ELEV) | Fødselsdato og navn | En elevmappe per elev |
| Barnehagebarnarkiv (BARNEHAGE) | Fødselsdato og navn | En barnemappe per barn |
| Møtearkiv (MOTE) | Utvalg | En møtemappe per møte |

I personarkivene er primærklasseringen fødselsdato og navn, skrevet «14.03.2016 Måke, Mathias» (koden i registeret).
Den skjermes alltid i den offentlige journalen (`skjerm_klasse`). Personene i registrene har også et fødselsnummer.
Det er oppdiktet: riktig oppbygning (dato og individnummer), men feil kontrollsifre, så det kan ikke tilhøre noen.
Testen feiler hvis et nummer består kontrollen. Fødselsnummeret brukes ikke som klassering og står aldri i
dokumentene.

Arkivnøkkelen er oppdiktet for Sirevåg kommune og bygger på «Felles arkivnøkkel for statsforvaltningen»
(fellesnøkkelen). Den ble fastsatt av Riksarkivaren i 1988, og den siste utgaven er fra 1. desember 1999 (gjeldende
fra 1. januar 2000), der klasse 1 ble bygd om. Nøkkelen har ikke vært påbudt for staten siden 2018, men oppbygningen
lever videre i mange statlige arkivnøkler.

- Klasse 0 Organisasjon og administrasjon, 1 Økonomi og 2 Stillinger og personell (egenforvaltningen) følger
  hovedgruppene og gruppene i den siste utgaven av fellesnøkkelen, med titler tilpasset en kommune (blant annet 12
  Økonomiforvaltning og 13 Regnskap og revisjon). Budsjettgruppene (11) følger modellutbyggingen i nøkkelen.
- Kommunens tillegg står på plasser som er ledige i fellesnøkkelen: 015 Valg, 017 Delegering og fullmakter, 047
  Innsyn og offentlig journal, 14 Inntekter, avgifter og innkjøp, og 256 Varsling om kritikkverdige forhold.
- Klasse 3–8 er kommunens fagområder og er diktet for Ark-Ivar. Klasse 9 er ledig.
- På hvert nivå er 0 «Generelt» og 9 «Annet», som i fellesnøkkelen.
- En mappe legges på laveste nivå: gruppen (tre sifre), eller hovedgruppen (to sifre) når den ikke er delt videre.
- Saker legges på emnet de gjelder: en anskaffelse på det som kjøpes, en høring på det den handler om, et tilsyn på
  området det gjelder.
- Fire klasser viser til en annen arkivdel og kan ikke brukes på en mappe i sakarkivet (`henvisning` i dataene):
  221 Personalmapper, 33 Elevmapper, 34 Barnemapper i barnehagene og 53 Eiendomsmapper. I fellesnøkkelen er 221
  stedet der emneordningen brytes med én mappe per person.

## Arbeidsgangen og saksmalene

Studenten er arkivtjenesten. For hvert dokument i postmottaket velger studenten først hva som skal skje med det.
Skal det i arkivet, er rekkefølgen den samme som i et sak- og arkivsystem:

1. **Søk etter mappen.** Søket går i mappenes tittel, klassering og saksnummer og i dokumentene som ligger i dem.
   Finnes mappen, skal dokumentet inn i den.
2. **Opprett mappen hvis den ikke finnes.** Studenten velger en saksmal (`saksmaler` i `sirevag.json`). Malen fyller
   inn arkivdelen og et forslag til tittel med hakeparenteser («Elevmappe – [etternavn, fornavn]») og har et søketips
   («Søk på navnet til eleven og «elevmappe»»). Studenten velger primærklasseringen, ansvarlig enhet, og om tittelen
   og klasseringen skal skjermes (da med hjemmel).
3. **Registrer journalposten i mappen.** En mappe kan stå tom, og den kan få flere journalposter. Dokumentet
   journalføres eller arkiveres uten journalføring. Studenten kan også lage egne journalposter uten dokument fra
   postmottaket; de vurderes ikke.

Vurderingen kommer når dokumentet er registrert. En ny sak skal ha sin egen mappe: legger studenten dokumenter fra
flere saker i samme mappe, er mappen feil for alle dokumentene i den, og vurderingen følger med når et dokument
legges til eller angres. Ny saksmal: legg den i `saksmaler` med kode, navn, arkivdel, forslag til tittel,
beskrivelse og søketips.

## Regler for alt innhold

1. **Oppdiktet.** Ingen virkelige privatpersoner, firmaer eller lag. E-postadresser ender på `.example`
   (fornavn.etternavn@epost.example, post@firmanavn.example, kommunens ansatte fornavn.etternavn@sirevag.example).
   Ingen telefonnumre. Ingen fødselsnumre i dokumentteksten (skriv fødselsdatoen når det trengs).
2. **Navn.** Bruk navnene som står i registrene i `sirevag.json` (eiendommer med hjemmelshavere, ansatte, elever og
   barn med foresatte). **Kommunens ansatte, elevene og barnehagebarna har dyrenavn med bokstavrim** (Gunnar
   Grevling, Frida Frosk, Mathias Måke), og de foresatte har samme etternavn som barnet. Det er med vilje: bruk
   navnene som de står, uten å spøke med dem i tekstene. Alle andre personer har vanlige navn. Nye personer får vanlige norske navn med etternavn fra Jæren (Auglend, Mæland, Refsnes,
   Tengesdal, Rauneset, Aase, Nærland, Tjensvoll, Haver, Lyngnes, Oma, Særheim, Kyllingstad, Gausland, Herikstad,
   Odland, Voll, Skadberg, Vagle). Ikke gjenbruk etternavnene til ansatte, elever og barn på andre personer.
3. **Troverdige dokumenter.** Skriv hvert dokument slik det ville sett ut i en norsk kommune: saklig, konkret, med
   datoer, beløp, paragrafer der det er naturlig, og tydelig hva avsenderen ber om eller kommunen vedtar. 3–8 avsnitt.
   Brev fra innbyggere er enklere og mer personlige enn brev fra kommunen. Vedtak har klageadgang. Ikke skriv hilsen
   («Hei») og underskrift som egne korte avsnitt i brev; avslutt siste avsnitt naturlig, og sett underskriften
   («Med hilsen Gunnar Grevling, byggesaksbehandler») som siste avsnitt.
4. **Mellomtitler.** Et avsnitt som begynner med `## ` blir en mellomtittel. Bruk det i saksframlegg (Bakgrunn,
   Vurdering, Forslag til vedtak), protokoller (ett per sak), innkallinger og lengre vedtak. Ingen annen formatering
   (ikke lister med streker, ikke fet skrift, ikke tabeller). Linjeskift i et avsnitt er lov (`\n`), for eksempel i en
   saksliste.
5. **Saksnummer.** Kommunens egne dokumenter viser til mappen med plassholderen `{{sak}}` (for eksempel «Vår ref.:
   {{sak}}» sist i første avsnitt, eller «arkivsak {{sak}}»). En annen mappe vises til med `{{sak:<nøkkel>}}`, som må
   finnes i `sirevag.json`. Innbyggere kan vise til «deres ref. {{sak}}» når de svarer på et brev fra kommunen.
   Ikke dikt opp saksnummer med tall. Plassholderne virker bare i emnet, teksten, vedleggene og utkastet til et
   dokument, ikke i titler og navn. Et dokument i postmottaket (`oppgaver.json`) ligger ikke i en mappe ennå, så der
   brukes alltid `{{sak:<nøkkel>}}`.
   **Utkast.** Kommunens egne utgående dokumenter i `sirevag.json` kan ha feltet `utkast`: en liste med avsnitt som
   er saksbehandlerens grove første utkast. Dokumentet får da to versjoner, utkastet som versjon 1 og den ferdige
   teksten som versjon 2. Utkastet er kortere, kan ha stikkord og merknader i hakeparentes, og motsier ikke den
   ferdige teksten. Dokumenter i postmottaket har ikke utkast.
6. **Språk.** Klart norsk bokmål. Ikke engelske ord der norske finnes. Ikke tankestrek som komma; bruk «–» bare i
   titler mellom ledd. Datoer skrives «17. november 2026» i tekst.
7. **Lovhenvisninger.** Bruk bare hjemlene i avsnittet «Regelverk» under, og bare der du er sikker. Er du i tvil,
   skriv uten paragraf («etter plan- og bygningsloven»).

## Slik registrerer Sirevåg kommune

- **Sakstittel:** det saken gjelder, fra det generelle til det spesielle, med ledd skilt av « – ».
  Eksempler: «Byggesak – tilbygg til bolig – Fyrveien 8 – gnr. 9 bnr. 112», «Tilsetting – byggesaksbehandler –
  stillingsnr. 26/41», «Anskaffelse – renholdstjenester for kommunale bygg 2027–2030».
- **Journalposttittel:** dokumentets innhold, ikke «Søknad» alene. Eksempler: «Søknad om tillatelse til tiltak –
  tilbygg til bolig – Fyrveien 8», «Foreløpig svar – søknad om tilbygg til bolig – Fyrveien 8».
- **Journalposttyper:** I inngående, U utgående, N organinternt dokument for oppfølging (notat til en annen enhet som
  skal svare), X organinternt dokument uten oppfølging (notat til saken, referat), S saksframlegg til politisk
  behandling.
- **Journalstatus:** inngående dokumenter har J (journalført). Utgående har E (ekspedert). Interne har F
  (ferdigstilt fra saksbehandler) eller J. Saker som er avsluttet (status A), har A (arkivert) på alle dokumentene.
- **part:** avsenderen på inngående, mottakeren på utgående. På interne dokumenter og saksframlegg: navnet på den
  ansatte som har skrevet dokumentet.
- **Tilgangskode og skjerming:** se «Regelverk». `tilgangskode` på journalposten gir hjemmelen for at dokumentet
  er unntatt offentlighet. `offentlig_tittel` settes når tittelen røper noe som er taushetsbelagt; da er det den som
  vises i offentlig journal (skriv den uten navn, med «*****» der navnet sto). `skjerm_part` er `true` når navnet
  på avsender eller mottaker ikke kan vises i offentlig journal.

## Regelverk (slik det gjelder 20. november 2026)

Dette er kontrollert mot Lovdata. Bruk bare disse hjemlene, og bare der de passer.

- **Arkivlova** (lov 20. juni 2025 nr. 96 om dokumentasjon og arkiv) og **arkivforskrifta** (forskrift 17. desember
  2025 nr. 2647) gjelder fra 1. januar 2026 og har erstattet arkivlova 1992 og forskrift om offentlege arkiv.
  **Arkivverket heter nå Nasjonalarkivet.** Nasjonalarkivet fører tilsyn etter arkivlova § 21. Organet skal ha en
  dokumentasjonsplan (arkivlova § 8). Skriv aldri «Arkivverket», «arkivplan» eller «arkivbegrensning» om dagens
  forhold: ordet arkivbegrensning står ikke lenger i regelverket. Nå avgjør organet hva det skal forvalte som arkiv
  (arkivlova § 5 og arkivforskrifta § 1).
- **Journalføringsplikten** står i arkivforskrifta § 14, jf. offentleglova § 10:
  - første ledd: alle inngående og utgående dokumenter som er saksdokumenter for organet, «dersom dei er eller blir
    sakshandsama og har verdi som stadfesting av dei tilhøva dei gjeld»
  - andre ledd: organinterne dokumenter journalføres så langt organet finner det tjenlig. Disse skal alltid
    journalføres (offentleglova § 16 første ledd bokstav a til d): saksframlegg med vedlegg til et folkevalgt organ,
    sakliste til møte i folkevalgt organ, dokumenter fra eller til kontrollutvalg, revisjon og klagenemnder
  - tredje ledd: dokumenter i saker om innsyn er ikke omfattet av journalføringsplikten, med mindre de gjelder eller
    inneholder en nærmere begrunnelse, en klage, et krav om betaling for innsyn eller et spørsmål om hvordan innsyn
    skal gis. Et innsynskrav og et vanlig svar arkiveres derfor uten journalføring; en klage over avslag journalføres.
- **Forvaltningsloven av 1967 gjelder fortsatt** (ny forvaltningslov er vedtatt, men ikke satt i kraft).
  Taushetsplikt: fvl. § 13 første ledd nr. 1 (noens personlige forhold) og nr. 2 (drifts- eller
  forretningsforhold). Fødselsdato, bopel, sivilstand, yrke og arbeidssted er ikke personlige forhold (§ 13 andre
  ledd). Klagefrist tre uker (fvl. § 29), klagerett § 28. Foreløpig svar § 11 a.
- **Offentleglova:** innsynsrett § 3, journal § 10, avslag og begrunnelse § 31 (nærmere begrunnelse kan kreves innen
  tre uker), klage § 32 (til Statsforvalteren når kommunen har avslått). Unntakene i §§ 14 og 15 gjelder ikke for
  saksframlegg og saklister til folkevalgte organer (§ 16).
- **Opplæringslova** (lov 9. juni 2023 nr. 30, i kraft 1. august 2024): § 11-6 individuelt tilrettelagt opplæring
  (ordet «spesialundervisning» brukes ikke lenger), § 11-7 vedtak og sakkyndig vurdering fra PP-tjenesten, § 11-10
  individuell opplæringsplan. Skolemiljø: kapittel 12. Andre paragrafer: skriv bare «etter opplæringslova».
- **Barnehageloven:** § 31 spesialpedagogisk hjelp, § 34 sakkyndig vurdering, § 35 vedtak. Foreldrebetaling:
  «forskrift om foreldrebetaling i barnehager» uten paragraf.
- **Kommuneloven:** § 11-3 innkalling og saksliste, § 11-4 møtebok, § 11-5 lukking av møter.
- **Plan- og bygningsloven:** § 12-8 oppstart av reguleringsplanarbeid, § 12-10 høring og offentlig ettersyn (minst
  seks uker), § 12-12 vedtak av reguleringsplan, § 19-2 dispensasjon, § 20-1 tiltak som krever søknad, § 21-3
  nabovarsel. Andre: skriv «etter plan- og bygningsloven».
- **Arbeidsmiljøloven:** kapittel 2 A om varsling (uten paragraf).
- Anskaffelser: skriv «anskaffelsesregelverket» uten paragraf.

### Tilgangskodene (hjemmel for unntak fra offentlighet)

| Kode | Hjemmel | Brukes på |
|---|---|---|
| 13-1 | Offl. § 13 første ledd, jf. fvl. § 13 første ledd nr. 1 | Personlige forhold: helse, familie, privatøkonomi, elev- og barneopplysninger, personalopplysninger |
| 13-2 | Offl. § 13 første ledd, jf. fvl. § 13 første ledd nr. 2 | Forretningshemmeligheter, for eksempel enhetspriser i et tilbud etter at leverandør er valgt |
| 13-3 | Offl. § 13 første ledd, jf. særlov | Taushetsplikt etter særlov (barnevern, helse, sosiale tjenester, skatt og annet) |
| 14 | Offl. § 14 første ledd | Kommunens egne notater til intern saksforberedelse. Ikke saksframlegg og saklister til folkevalgte organer |
| 15-1 | Offl. § 15 første ledd | Dokumenter innhentet fra underordnet organ til intern saksforberedelse |
| 15-2 | Offl. § 15 andre ledd | Råd og vurderinger kommunen har hentet inn utenfra, for eksempel fra advokat |
| 15-3 | Offl. § 15 tredje ledd | Dokumenter om innhenting, og innkallinger og referater fra møter om slike råd |
| 17 | Offl. § 17 | Taler og reiseprogram for kongehuset |
| 18 | Offl. § 18 | Rettssaksdokumenter |
| 19 | Offl. § 19 | Konsultasjoner med Sametinget og andre samiske interesser |
| 20 | Offl. § 20 | Norges utenrikspolitiske interesser |
| 21 | Offl. § 21 | Nasjonale forsvars- og sikkerhetsinteresser |
| 22 | Offl. § 22 | Statlige budsjettsaker og foreløpige budsjettrammer |
| 23-1 | Offl. § 23 første ledd | Opplysninger som må skjermes av hensyn til forhandlinger og økonomi-, lønns- og personalforvaltningen |
| 23-2 | Offl. § 23 andre ledd | Forhandlinger om rammeavtaler med landbruks-, fiskeri- og reindriftsorganisasjonene |
| 23-3 | Offl. § 23 tredje ledd | Tilbud og anskaffelsesprotokoll til leverandør er valgt |
| 23-4 | Offl. § 23 fjerde ledd | Eierstyring av selskaper kommunen har eierinteresser i |
| 24-1 | Offl. § 24 første ledd | Kontroll- og reguleringstiltak som ellers ville bli motvirket |
| 24-2 | Offl. § 24 andre ledd første punktum | Melding eller tips om lovbrudd fra private |
| 24-2b | Offl. § 24 andre ledd andre punktum | Andre dokumenter om lovbrudd, til saken er avgjort |
| 24-3 | Offl. § 24 tredje ledd | Opplysninger som kan lette straffbare handlinger, utsette noen for fare eller skade sårbar natur |
| 25 | Offl. § 25 første ledd | Søknader, innstilling og andre dokumenter i en tilsettingssak. Den offentlige søkerlisten er offentlig |
| 25-3 | Offl. § 25 tredje ledd | Lønnsoppgaver |
| 26-1 | Offl. § 26 første ledd | Eksamenssvar, karakterer og vitnemål |
| 26-2 | Offl. § 26 andre ledd | Hvem som skal få pris eller hedersbevisning |
| 26-3 | Offl. § 26 tredje ledd | Personbilder i personregister og opplysninger fra personovervåking |
| 26-4 | Offl. § 26 fjerde ledd | Forskningsideer og forskningsprosjekter |
| 26-5 | Offl. § 26 femte ledd | Fødselsnummer |
| 26-6 | Offl. § 26 sjette ledd | Sammenstillinger laget etter innsynskrav etter personvernforordningen |

Listen har en kode for hvert unntak i offentleglova §§ 13–26. Flere av dem (17, 19, 20, 22, 23-2) er sjelden eller aldri aktuelle i en kommune, men står der så studenten må velge riktig hjemmel.

De fleste dokumenter i en kommune er offentlige og har ingen tilgangskode. Sett bare tilgangskode der det finnes en
grunn. Et dokument med tilgangskode kan ha åpen tittel og åpen part når de ikke røper noe.

### Praksis i Sirevåg kommune

- **Sakarkivet:** én saksmappe per sak. Saker som gjelder én ansatt, én elev, ett barn eller en byggesak på én
  eiendom, ligger ikke her. Tilsettingssaker (én per utlyst stilling, klasse 212) og varslingssaker (256) ligger her.
  En sak som skal til politisk behandling, har saksframlegget (type S, alltid offentlig) i saksmappen, deretter
  saksprotokollen fra møtet (type X, kanal protokoll, tittel «Saksprotokoll – kommunestyret 29.10.2026 – PS 60/26 –
  …») og meldingen om vedtak til partene (type U).
- **Eiendomsarkivet:** én mappe per byggesak, delingssak eller lignende på eiendommen, klassifisert på gårds- og
  bruksnummer. Tittelen har sakstypen, tiltaket, adressen og gnr./bnr. Reguleringsplaner ligger i sakarkivet (512).
  Byggesaker er offentlige; fødselsnummer står aldri i dokumentene.
- **Personalarkivet:** én personalmappe per ansatt, opprettet når den signerte arbeidsavtalen kommer inn (den er det
  første dokumentet). Mappen har tittel «Personalmappe – Etternavn, Fornavn», `offentlig_tittel` «Personalmappe –
  *****» og tilgangskode 13-1. Primærklasseringen er fødselsdato og navn («15.08.1990 Grevling, Gunnar»), og den
  skjermes. Enheten er HR og saksansvarlig MMA. I mappen ligger blant annet arbeidsavtale, kopi av
  tilbudsbrev, vitnemål og attester, lønnsfastsetting, permisjoner som har betydning for lønn, pensjon eller
  ansiennitet, oppfølging av sykefravær, advarsler og oppsigelse. Sykmeldinger, egenmeldinger, timelister, ferie og
  korte velferdspermisjoner ligger ikke her (de føres i lønns- og personalsystemet).
- **Elevarkivet:** én elevmappe per elev, opprettet ved innskrivingen. Tittel «Elevmappe – Etternavn, Fornavn»,
  `offentlig_tittel` «Elevmappe – *****», tilgangskode 13-1, primærklassering fødselsdato og navn (skjermet). Enheten
  er OPP, og rektor ved skolen er saksansvarlig
  (TTR for Sirevåg skole, IIL for Sirevåg ungdomsskole). PP-tjenesten er interkommunal («PP-tjenesten for Sirevåg og
  Kvassheim», Kvassheim kommune er vertskommune), så dokumenter derfra er inngående.
- **Barnehagebarnarkivet:** én barnemappe per barn, opprettet ved søknaden om plass. Tittel «Barnemappe – Etternavn,
  Fornavn», `offentlig_tittel` «Barnemappe – *****», tilgangskode 13-1, primærklassering fødselsdato og navn
  (skjermet). Enheten er OPP, og styreren i barnehagen er
  saksansvarlig (FFR for Fyrlykta, LLE for Tjørnhaugen).
- **I de tre personarkivene** har hver journalpost tilgangskode (vanligvis 13-1), en `offentlig_tittel` uten navn
  når tittelen har navnet (for eksempel «Søknad om permisjon – *****»), og `skjerm_part` `true` når parten er
  personen selv eller de foresatte. Titlene skal fortsatt si hva dokumentet gjelder.
- **Møtearkivet:** én møtemappe per møte, klassifisert på utvalget. Tittel «Kommunestyret – møte 29.10.2026».
  Enheten er KD og saksansvarlig PPI (politisk sekretær). Mappen har innkallingen med saklisten (type X, kanal
  innkalling, journalført, jf. offl. § 16 første ledd bokstav b) og møteprotokollen (type X, kanal protokoll).
  `part` er «Petra Pinnsvin». Saksframleggene ligger i saksmappene i sakarkivet; saklisten viser til dem med
  «arkivsak {{sak:<nøkkel>}}» der saken ligger i arkivet. Møtene er åpne, og dokumentene er offentlige.
  Kontrollutvalget har eget sekretariat utenfor kommunen (Jæren kontrollutvalgssekretariat IKS, Kvassheim), så
  innkallinger og protokoller derfra er inngående dokumenter (type I) og journalføres.
- **Titler:** stor forbokstav, ikke punktum til slutt, ledd skilt med « – », ingen forkortelser som ikke er kjent.
  Ordene «søknad» og «svar» brukes ikke i sakstittelen, men gjerne i journalposttittelen.

## Formatet

Mappene står i listen `saker` i `arkivar/data/sirevag.json`. Hver mappe:

```json
{
  "nokkel": "byggesak-fyrveien-8",
  "tittel": "Byggesak – tilbygg til bolig – Fyrveien 8 – gnr. 9 bnr. 112",
  "offentlig_tittel": null,
  "tilgangskode": null,
  "arkivdel": "EIENDOM",
  "klasse": "9/112",
  "enhet": "PBE",
  "saksansvarlig": "GGR",
  "saksdato": "2026-08-17",
  "status": "B",
  "journalposter": [
    {
      "type": "I",
      "tittel": "Søknad om tillatelse til tiltak – tilbygg til bolig – Fyrveien 8",
      "offentlig_tittel": null,
      "dokumentdato": "2026-08-14",
      "part": "Vibeke og Trond Auglend",
      "skjerm_part": false,
      "status": "J",
      "journalfort": true,
      "tilgangskode": null,
      "dokument": {
        "kanal": "brev",
        "mottatt": "2026-08-17",
        "avsender": {"navn": "Vibeke og Trond Auglend", "adresse": "Fyrveien 8, 4399 Sirevåg", "epost": null},
        "mottaker": null,
        "emne": "Søknad om tillatelse til tiltak – tilbygg til bolig, Fyrveien 8",
        "tekst": ["Første avsnitt.", "Andre avsnitt."],
        "vedlegg": ["Situasjonsplan", "Tegninger"]
      }
    }
  ]
}
```

- `nokkel`: en fast nøkkel for mappen (små bokstaver, tall og bindestrek). Fasiten i `oppgaver.json` viser til den.
- `klasse` er primærklasseringen: i sakarkivet en kode fra arkivnøkkelen (laveste nivå); i eiendomsarkivet gårds- og
  bruksnummer; i personarkivene fødselsdato og navn, skrevet nøyaktig som `kode` i registeret («14.03.2016 Måke,
  Mathias»); i møtearkivet utvalgskoden. Alt står i `sirevag.json`.
- `status` på mappen: B (under behandling) eller A (avsluttet).
- `kanal`: brev, epost, skjema (inngående), notat, saksframlegg, protokoll, innkalling, avtale, vedtak.
- Inngående (I): `mottatt` er datoen kommunen fikk dokumentet (samme dag for e-post, 1–4 dager etter dokumentdatoen
  for brev), `mottaker` er `null`, og `part` er lik `avsender.navn`.
- Alle andre typer: `mottatt` er `null`, og `avsender.navn` er «Sirevåg kommune» eller «Sirevåg kommune, <enhet>»
  (adresse «Rådhusgata 1, 4399 Sirevåg», epost «postmottak@sirevag.example»). Utgående (U) har `mottaker`
  (navn og gjerne adresse i én tekst), og `part` er mottakerens navn. Interne (N, X, S) har `mottaker` `null`.
- `journalfort`: `true`, bortsett fra dokumenter som er arkivert uten journalføring (da `false`), for eksempel et
  innsynskrav.
- Dokumentene i en mappe står i den rekkefølgen de ble registrert (mottatt-datoen for inngående, ellers
  dokumentdatoen).
- Mapper i personarkivene (PERSONAL, ELEV, BARNEHAGE) har alltid `tilgangskode` og `offentlig_tittel` på mappen, og
  ingen tittel som vises i offentlig journal (journalpostens `offentlig_tittel`, eller `tittel` når den er `null`)
  kan ha navnet til personen. Har parten samme etternavn som personen (foresatte), er `skjerm_part` `true`.

## Postmottaket (`oppgaver.json`)

Hvert dokument har feltene `id`, `kanal` (brev, epost eller skjema), `dokumentdato`, `mottatt`, `avsender`, `emne`,
`tekst`, `vedlegg` og `fasit`. Fasiten:

- `handling`: `journalfor`, `arkiver` (arkiveres uten journalføring), `fagsystem` eller `ikke_arkiver`.
- `sak` (når dokumentet arkiveres): `type` `ny` (med `tittel`, og `offentlig_tittel` i personarkivene) eller
  `eksisterende` (med `nokkel`), `arkivdel` og `klasse` (en liste med primærklasseringer: alle godtas, den beste
  står først). Hvert dokument som starter en ny sak, starter sin egen: en mappe der studenten samler flere av dem,
  er feil for alle dokumentene i den. Klasseringen på en ny mappe skal skjermes i personarkivene og ellers ikke,
  og tittelen skal bare skjermes når fasiten har en `offentlig_tittel`.
- `type` (alltid `I`), `tittel`, `offentlig_tittel`, `tilgangskode` (en liste: alle godtas, tom = ingen),
  `skjerm_tittel` og `skjerm_part` (`null` = vurderes ikke), `sensitive_ord` (navn som ikke kan stå i en offentlig
  tittel) og `part` (kortformer av avsenderen som også godtas, for eksempel «Statsforvalteren», «FAU» eller én av
  to foresatte). Avsenderen sammenliknes ord for ord uten hensyn til rekkefølge, småord og selskapsform: alle
  ordene i navnet må være med, og studenten kan skrive mer. En kortform godtas bare alene («Statsforvalteren i
  Agder» er ikke Statsforvalteren i Rogaland).
- `forklaring` med nøklene `handling`, `sak`, `arkivdel`, `klasse`, `tilgang` og `skjerming`. Forklaringen skal si
  hvorfor, og gjerne hva som ville vært feil og hvorfor. `skjerming` skal stå på alle dokumenter som journalføres,
  og på alle som starter en ny mappe.

Postmottaket skal øve på alle arkivdelene og alle fire behandlingsmåtene. Hvert dokument skal ha en naturlig mappe:
finnes saken eller personen i arkivet, skal dokumentet dit, ellers opprettes en ny mappe med en tittel som bare
gjelder den saken. Ingen samlesaker som «Innsynskrav 2026» eller «Diverse henvendelser».
Det eneste unntaket er samlemappen «Høringer 2026» (klasse 008): der legges høringer kommunen har mottatt, men ikke
uttaler seg til. En høring kommunen skal behandle, får sin egen sak på emnet.

## Det som må følges med på

- **Ny forvaltningslov** (lov 20. juni 2025 nr. 81) er vedtatt, men ikke satt i kraft. Når den trer i kraft,
  flytter taushetsplikten fra § 13 til §§ 31 og 32, og hjemlene i tilgangskodene og forklaringene må oppdateres.
- **Skjerming i personarkivene** følger vanlig kommunal praksis (mappen, klasseringen og navnet skjermes). Hvert
  dokument skal egentlig vurderes for seg; en vanlig søknad uten taushetsbelagte opplysninger er ikke uten videre
  unntatt fra innsyn. Forklaringene sier dette der det er aktuelt.
- **Primærklassering på fødselsdato og navn** er valgt for Sirevåg kommune. Mange kommuner bruker fødselsnummer.
- **Varsling:** hvilken hjemmel som skal brukes for et varsel, avgjøres konkret, og praksis varierer. Fasiten godtar
  13-1 og 14.
- **Årsmeldingen fra idrettslaget** arkiveres uten journalføring i fasiten. Mange kommuner ville journalført den.
- Fasiten for hvert dokument har ett riktig svar for behandlingen. Der regelverket lar kommunen velge (for eksempel
  å journalføre et innsynskrav likevel), følger fasiten rutinen i Sirevåg kommune, og forklaringen sier det.
