-- Ark-Ivar: brukere, godkjente e-postdomener, engangskoder og klasser. Kan kjøres flere ganger (IF NOT EXISTS).
-- Endres aldri etter at den er lagt ut: rett med en ny migrering. Alle tabellene har prefikset arkivar_.

CREATE TABLE IF NOT EXISTS arkivar_bruker (
    id CHAR(36) NOT NULL PRIMARY KEY,
    epost VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    navn VARCHAR(200) NOT NULL,
    passord_hash VARCHAR(255) NOT NULL,
    rolle VARCHAR(20) NOT NULL DEFAULT 'student',
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    generasjon INT NOT NULL DEFAULT 1,
    epost_bekreftet DATETIME(6) NULL,
    registrert_via VARCHAR(20) NOT NULL,
    opprettet DATETIME(6) NOT NULL,
    endret DATETIME(6) NOT NULL,
    sist_innlogget DATETIME(6) NULL,
    UNIQUE KEY arkivar_bruker_epost (epost)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Studenter, lærere og administratorer (e-postadressen i små bokstaver er brukernavnet)';

CREATE TABLE IF NOT EXISTS arkivar_domene (
    id CHAR(36) NOT NULL PRIMARY KEY,
    domene VARCHAR(253) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    sted VARCHAR(200) NOT NULL,
    underdomener TINYINT(1) NOT NULL DEFAULT 1,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    opprettet DATETIME(6) NOT NULL,
    endret DATETIME(6) NOT NULL,
    UNIQUE KEY arkivar_domene_domene (domene)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Godkjente e-postdomener: studenter med adresse herfra kan registrere seg selv';

CREATE TABLE IF NOT EXISTS arkivar_engangskode (
    id CHAR(36) NOT NULL PRIMARY KEY,
    bruker_id CHAR(36) NOT NULL,
    formal VARCHAR(20) NOT NULL,
    kode_hash CHAR(64) NOT NULL,
    utloper DATETIME(6) NOT NULL,
    brukt DATETIME(6) NULL,
    opprettet DATETIME(6) NOT NULL,
    KEY arkivar_engangskode_bruker (bruker_id, formal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Koder sendt på e-post: bekreftelse av adressen og nytt passord (bare SHA-256 av koden lagres)';

CREATE TABLE IF NOT EXISTS arkivar_klasse (
    id CHAR(36) NOT NULL PRIMARY KEY,
    navn VARCHAR(200) NOT NULL,
    kode VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    laerer_id CHAR(36) NOT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    opprettet DATETIME(6) NOT NULL,
    endret DATETIME(6) NOT NULL,
    UNIQUE KEY arkivar_klasse_kode (kode),
    KEY arkivar_klasse_laerer (laerer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Klasser: læreren deler koden, og studentene melder seg inn med den';

CREATE TABLE IF NOT EXISTS arkivar_klassemedlem (
    klasse_id CHAR(36) NOT NULL,
    bruker_id CHAR(36) NOT NULL,
    innmeldt DATETIME(6) NOT NULL,
    PRIMARY KEY (klasse_id, bruker_id),
    KEY arkivar_klassemedlem_bruker (bruker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Studentene i en klasse';
