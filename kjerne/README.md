# Bivrost Kjerne

PHP-pakken `bivrost/kjerne` (navnerom `Bivrost\Kjerne\`, PSR-4, PHP 8.2+). Se `../docs/arkitektur.md` for helheten.

## Modulene

| Mappe | Innhold | Krav |
|---|---|---|
| `Konfig/` | `AppKonfig` (appens oppsett), `Env` (.env utenfor webroten) | BIV-APP-01, BIV-SIK-03 |
| `Database/` | `Db` (PDO, transaksjoner, låser), `SqlDeler`, `Felt` (typekontroll) | BIV-PLT-05 |
| `Migrering/` | `Migrator` (rekke per pakke, lås, sjekksum) | BIV-DRF-04 |
| `Logg/` | `Logg`, `Hendelse`, `Aktor`, `LoggObjekt`, `Verdi`, `LoggLager`/`DbLoggLager`, `CloudEvent`, `Syslog`, `Ocsf`, `VideresendendeLoggLager` | BIV-LOG-01–08 |
| `Tilgang/` | `Urn`, `Rolleregister`, `Rbac` | BIV-ROL-01–06 |
| `Oidc/` | `OidcKlient`, `StandardOidcKlient`, `OidcOppsett`, `Identitet` | BIV-AUT-01–03 |
| `Innlogging/` | `Passordregler`, `Passordhash`, `Totp`, `Forsok`, `LokalBrukere`, `LokalInnlogging` | BIV-AUT-04–08 |
| `Okt/` | `Okt` (økter i databasen), `Innlogget` | BIV-AUT-07 |
| `Http/` | `Foresporsel`, `Svar` (RFC 9457), `Ruter`, `Csrf`, `Sikkerhetshoder`, `Feilside`, `UtgaendeHttp` | BIV-SIK-01–06 |
| `Preferanser/` | `Preferanser` (SCIM-tilordning), `Preferansetjeneste`, `SentralPreferanser` | BIV-PRF-01–05 |
| `Oppdatering/` | `Oppdaterer`, `Pakke`, `Pakkeleser`, `GithubUtgivelser`, `Signatur`, `Utgivernokler` (klarert utgivernøkkel, TOFU), `Tilbakerulling`, `OppdateringsApi` | BIV-DRF-05–10, BIV-DRF-14 |
| `Sikkerhetskopi/` | `Databasekopi`, `Filkopi`, `Kopilager`, `Mappevern` | BIV-DRF-08, BIV-DRF-09 |
| `Eksport/` | `Eksport`, `EksportBidrag`, `JsonStrom` | BIV-EKS-01, BIV-EKS-02 |
| `Installasjon/` | `Installasjon` | BIV-DRF-02, BIV-DRF-03 |
| `Sprak/` | `Tekster`, `IcuFormatter`, `Klartekst`, `Sprakpakke`, `Sprakkontroll`, `Xliff` | BIV-SPR-01–08 |
| `Ressurser/` | `Ressurser` (designpakken fra appens server) | BIV-GRS-02 |
| `Kjerne.php` | Setter sammen tjenestene og har rutene under `/bivrost/` | BIV-APP-01 |

Andre mapper: `migrasjoner/` (Kjernens egne), `i18n/` (språkfilene), `skjema/` (JSON Schema), `openapi.json`,
`ressurser/` (passordlisten; designpakken i versjonspakken), `bin/` (verktøy for utvikling), `tester/`.

## Konfigurasjonen fra appen

```php
// <app>/bivrost.php
return [
    'app' => 'gerd',
    'appnavn' => 'Gerð',
    'rot' => dirname(__DIR__),                  // installasjonsroten
    'webrot' => __DIR__ . '/public',
    'hovedsprak' => 'nb',
    'roller' => [
        ['navn' => 'arkivar', 'tekst' => ['nb' => 'Arkivar', 'nn' => 'Arkivar', 'en' => 'Archivist']],
        ['navn' => 'leser', 'tekst' => ['nb' => 'Leser', 'nn' => 'Lesar', 'en' => 'Reader']],
    ],
    'separasjon' => [['navn' => 'registrere-godkjenne', 'urner' => ['urn:bivrost:app:gerd:rolle:registrerer', 'urn:bivrost:app:gerd:rolle:godkjenner'], 'maks' => 1]],
    'migreringer' => __DIR__ . '/migrasjoner',
    'tabellprefikser' => ['gerd_'],
    'i18n' => __DIR__ . '/i18n',
    'pakke' => ['repo' => 'magnehg/gerd', 'tagprefiks' => 'gerd-v', 'mappe' => 'gerd/'],
    'kjerne_krav' => '^0.2',
    'okt' => ['inaktiv_minutter' => 30, 'maks_timer' => 12],
    'logg' => ['oppbevaring_dager' => 365, 'tabeller' => ['gerd_logg']], // appens faglogg (DbLoggLager)
];
```

## .env (utenfor webroten, 0640)

Se `.env.eksempel`. Installasjonen i nettleseren skriver databasen, adressen og nøklene; resten legges inn for hånd.
