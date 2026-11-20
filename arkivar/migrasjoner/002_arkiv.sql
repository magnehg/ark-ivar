-- Ark-Ivar: øvingsarkivene. Hver bruker har sitt eget arkiv for Sirevåg kommune med mapper og journalposter, og en
-- status per eksempeldokument i postmottaket. Arkivstrukturen (arkivdeler, klasser, registre, saksmaler, enheter og
-- tilgangskoder) og eksempeldokumentene er felles og ligger i arkivar/data/. Kan kjøres flere ganger (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS arkivar_arkiv (
    id CHAR(36) NOT NULL PRIMARY KEY,
    eier_id CHAR(36) NOT NULL,
    sak_sekvens INT NOT NULL DEFAULT 0,
    journal_sekvens INT NOT NULL DEFAULT 0,
    opprettet DATETIME(6) NOT NULL,
    nullstilt DATETIME(6) NULL,
    UNIQUE KEY arkivar_arkiv_eier (eier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Øvingsarkivet til én bruker, med tellerne for saksnummer og journalnummer';

CREATE TABLE IF NOT EXISTS arkivar_sak (
    id CHAR(36) NOT NULL PRIMARY KEY,
    arkiv_id CHAR(36) NOT NULL,
    nokkel VARCHAR(60) NULL,
    saksaar SMALLINT NOT NULL,
    sekvens INT NOT NULL,
    tittel VARCHAR(500) NOT NULL,
    offentlig_tittel VARCHAR(500) NULL,
    skjerm_tittel TINYINT(1) NOT NULL DEFAULT 0,
    tilgangskode VARCHAR(20) NULL,
    arkivdel VARCHAR(20) NOT NULL,
    klasse VARCHAR(60) NOT NULL,
    skjerm_klasse TINYINT(1) NOT NULL DEFAULT 0,
    mal VARCHAR(40) NULL,
    enhet VARCHAR(20) NOT NULL,
    saksansvarlig VARCHAR(20) NOT NULL,
    status CHAR(1) NOT NULL DEFAULT 'B',
    saksdato DATE NOT NULL,
    opprettet DATETIME(6) NOT NULL,
    UNIQUE KEY arkivar_sak_nummer (arkiv_id, saksaar, sekvens),
    KEY arkivar_sak_nokkel (arkiv_id, nokkel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mapper (saksmapper, personmapper, møtemapper). klasse er primærklasseringen i arkivdelens ordning: arkivnøkkel, gårds- og bruksnummer, fødselsdato og navn, eller utvalg; skjerm_klasse skjuler den i offentlig journal. mal er saksmalen mappen ble opprettet fra. nokkel er satt på mappene som fulgte med arkivet';

CREATE TABLE IF NOT EXISTS arkivar_journalpost (
    id CHAR(36) NOT NULL PRIMARY KEY,
    arkiv_id CHAR(36) NOT NULL,
    sak_id CHAR(36) NOT NULL,
    postnr INT NOT NULL,
    journalaar SMALLINT NOT NULL,
    sekvens INT NOT NULL,
    journalfort TINYINT(1) NOT NULL DEFAULT 1,
    type CHAR(1) NOT NULL,
    status CHAR(1) NOT NULL,
    tittel VARCHAR(500) NOT NULL,
    offentlig_tittel VARCHAR(500) NULL,
    dokumentdato DATE NOT NULL,
    journaldato DATE NOT NULL,
    saksbehandler VARCHAR(20) NOT NULL,
    enhet VARCHAR(20) NOT NULL,
    tilgangskode VARCHAR(20) NULL,
    skjerm_tittel TINYINT(1) NOT NULL DEFAULT 0,
    skjerm_part TINYINT(1) NOT NULL DEFAULT 0,
    part_navn VARCHAR(300) NOT NULL DEFAULT '',
    part_adresse VARCHAR(300) NULL,
    oppgave_id VARCHAR(60) NULL,
    startdokument VARCHAR(90) NULL,
    opprettet DATETIME(6) NOT NULL,
    KEY arkivar_journalpost_sak (sak_id, postnr),
    KEY arkivar_journalpost_arkiv (arkiv_id, journalaar, sekvens)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registreringer i en mappe (Noark: journalpost; journalfort = 0 er arkivert uten journalføring). Én korrespondansepart per post. Dokumentet ligger i arkivar/data: oppgave_id (postmottaket) eller startdokument (fulgte med arkivet)';

CREATE TABLE IF NOT EXISTS arkivar_oppgavestatus (
    arkiv_id CHAR(36) NOT NULL,
    oppgave_id VARCHAR(60) NOT NULL,
    handling VARCHAR(20) NOT NULL,
    journalpost_id CHAR(36) NULL,
    svar JSON NOT NULL,
    vurdering JSON NOT NULL,
    poeng INT NOT NULL,
    maks INT NOT NULL,
    behandlet DATETIME(6) NOT NULL,
    PRIMARY KEY (arkiv_id, oppgave_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Det studenten gjorde med et eksempeldokument i postmottaket, og vurderingen mot fasiten';
