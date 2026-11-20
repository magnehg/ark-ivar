-- Bivrost Kjerne: grunnlaget (BIV-DRF-04). Kan kjøres flere ganger (IF NOT EXISTS). Endres aldri etter at den er
-- lagt ut: rett med en ny migrering. Alle tabellene har prefikset bivrost_ (eierskap i en delt database).

CREATE TABLE IF NOT EXISTS bivrost_installasjon (
    id CHAR(36) NOT NULL PRIMARY KEY,
    app VARCHAR(40) NOT NULL,
    laget DATETIME(6) NOT NULL,
    kjerne VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Eiermarkør: installasjonen eier tabellene med prefiksene sine';

CREATE TABLE IF NOT EXISTS bivrost_innstilling (
    nokkel VARCHAR(100) NOT NULL PRIMARY KEY,
    verdi TEXT NOT NULL,
    endret DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bivrost_logg (
    nr BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id CHAR(36) NOT NULL,
    tid DATETIME(3) NOT NULL,
    kategori VARCHAR(20) NOT NULL,
    hva VARCHAR(200) NOT NULL,
    hvem_id VARCHAR(191) NULL,
    objekt_id VARCHAR(191) NULL,
    korrelasjon CHAR(36) NOT NULL,
    apper VARCHAR(500) NOT NULL DEFAULT '',
    hendelse JSON NOT NULL,
    UNIQUE KEY bivrost_logg_id (id),
    KEY bivrost_logg_tid (tid),
    KEY bivrost_logg_hvem (hvem_id),
    KEY bivrost_logg_objekt (objekt_id),
    KEY bivrost_logg_korrelasjon (korrelasjon),
    KEY bivrost_logg_hva (hva)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bare tillegg: rader endres aldri';

CREATE TABLE IF NOT EXISTS bivrost_rolle (
    urn VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    app VARCHAR(40) NULL,
    slag VARCHAR(20) NOT NULL,
    navn VARCHAR(64) NOT NULL,
    tekst JSON NOT NULL,
    beskrivelse JSON NOT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    opprettet DATETIME(6) NOT NULL,
    endret DATETIME(6) NOT NULL,
    deaktivert DATETIME(6) NULL,
    KEY bivrost_rolle_app (app)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rolleregisteret: roller som fjernes, deaktiveres';

CREATE TABLE IF NOT EXISTS bivrost_lokal_bruker (
    id CHAR(36) NOT NULL PRIMARY KEY,
    brukernavn VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    navn VARCHAR(200) NOT NULL,
    tittel VARCHAR(200) NULL,
    epost VARCHAR(254) NULL,
    passord_hash VARCHAR(255) NOT NULL,
    totp_hemmelighet VARCHAR(500) NOT NULL,
    totp_siste_steg BIGINT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    generasjon INT NOT NULL DEFAULT 1,
    opprettet DATETIME(6) NOT NULL,
    endret DATETIME(6) NOT NULL,
    passord_endret DATETIME(6) NOT NULL,
    UNIQUE KEY bivrost_lokal_bruker_navn (brukernavn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Driftsbrukere for nødinnloggingen (bare tilgangen drift)';

CREATE TABLE IF NOT EXISTS bivrost_gjenopprettingskode (
    nr BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bruker_id CHAR(36) NOT NULL,
    kode_hash CHAR(64) NOT NULL,
    laget DATETIME(6) NOT NULL,
    brukt DATETIME(6) NULL,
    KEY bivrost_gjenopprettingskode_bruker (bruker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bivrost_forsok (
    nr BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    omraade VARCHAR(40) NOT NULL,
    nokkel_hash CHAR(64) NOT NULL,
    tid DATETIME(6) NOT NULL,
    KEY bivrost_forsok_oppslag (omraade, nokkel_hash, tid),
    KEY bivrost_forsok_tid (tid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bivrost_okt (
    id_hash CHAR(64) NOT NULL PRIMARY KEY,
    data MEDIUMTEXT NOT NULL,
    laget BIGINT NOT NULL,
    sist_aktiv BIGINT NOT NULL,
    aktor_type VARCHAR(20) NULL,
    aktor_id VARCHAR(191) NULL,
    oidc_sid VARCHAR(191) NULL,
    KEY bivrost_okt_aktor (aktor_type, aktor_id),
    KEY bivrost_okt_sid (oidc_sid),
    KEY bivrost_okt_aktiv (sist_aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bivrost_preferanse (
    bruker VARCHAR(191) NOT NULL PRIMARY KEY,
    felles JSON NOT NULL,
    app JSON NOT NULL,
    felles_oppdatert DATETIME(6) NULL,
    synkronisert TINYINT(1) NOT NULL DEFAULT 1,
    endret DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Siste kjente preferanser fra Sentral og appens egne valg';

CREATE TABLE IF NOT EXISTS bivrost_jti (
    jti_hash CHAR(64) NOT NULL PRIMARY KEY,
    utloper BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Brukte utloggingstokener fra Sentral (vern mot gjenbruk)';
