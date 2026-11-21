-- Ark-Ivar: avskrivning av inngående dokumenter, koblingen fra et svar til dokumentet det besvarer, og merknader på
-- mapper og journalposter. Kan kjøres flere ganger (IF NOT EXISTS). Endres aldri etter at den er lagt ut.

CREATE TABLE IF NOT EXISTS arkivar_avskrivning (
    journalpost_id CHAR(36) NOT NULL PRIMARY KEY,
    arkiv_id CHAR(36) NOT NULL,
    mate VARCHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dato DATE NOT NULL,
    avskrevet_av CHAR(36) NULL,
    opprettet DATETIME(6) NOT NULL,
    KEY arkivar_avskrivning_arkiv (arkiv_id),
    KEY arkivar_avskrivning_av (avskrevet_av)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Avskrivningen av et inngående dokument (Noark: avskrivningsmåte og avskrivningsdato). avskrevet_av er journalposten som besvarer det (BU). Et inngående, journalført dokument uten rad her er en restanse';

CREATE TABLE IF NOT EXISTS arkivar_svar (
    journalpost_id CHAR(36) NOT NULL PRIMARY KEY,
    arkiv_id CHAR(36) NOT NULL,
    svar_paa CHAR(36) NOT NULL,
    KEY arkivar_svar_arkiv (arkiv_id),
    KEY arkivar_svar_paa (svar_paa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Et utgående dokument studenten skriver som svar på et inngående (svar_paa). Det inngående avskrives når svaret er ekspedert, unntatt når svaret er foreløpig';

CREATE TABLE IF NOT EXISTS arkivar_merknad (
    id CHAR(36) NOT NULL PRIMARY KEY,
    arkiv_id CHAR(36) NOT NULL,
    sak_id CHAR(36) NULL,
    journalpost_id CHAR(36) NULL,
    tekst TEXT NOT NULL,
    bruker_id CHAR(36) NULL,
    opprettet DATETIME(6) NOT NULL,
    KEY arkivar_merknad_journalpost (arkiv_id, journalpost_id),
    KEY arkivar_merknad_sak (arkiv_id, sak_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Merknader på en mappe (sak_id) eller en journalpost (journalpost_id), med hvem som skrev den og når';
