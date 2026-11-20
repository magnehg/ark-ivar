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
- Postmottaket med tolv eksempeldokumenter: studenten velger behandling, registrerer sak og journalpost med
  tilgangskode og skjerming, og får en vurdering mot fasiten med forklaring per kriterium. Kan angres.
- Journalen kan vises som offentlig journal, med skjermede titler og navn.
- Læreren ser hvor mange dokumenter hver student i klassen har behandlet, og hvor mange valg som var riktige.
