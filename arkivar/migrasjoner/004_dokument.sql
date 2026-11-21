-- Ark-Ivar: dokumenter studenten skriver selv i en mappe (utgående brev, interne notater og inngående brev på
-- blankt ark), én rad per versjon. Kan kjøres flere ganger (IF NOT EXISTS). Endres aldri etter at den er lagt ut.

CREATE TABLE IF NOT EXISTS arkivar_dokument (
    journalpost_id CHAR(36) NOT NULL,
    versjon INT NOT NULL,
    arkiv_id CHAR(36) NOT NULL,
    mal VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    innhold MEDIUMTEXT NOT NULL,
    opprettet DATETIME(6) NOT NULL,
    endret DATETIME(6) NOT NULL,
    PRIMARY KEY (journalpost_id, versjon),
    KEY arkivar_dokument_arkiv (arkiv_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dokumenter studenten har skrevet (innhold er JSON: kanal, mottatt, avsender, mottaker, emne, tekst og vedlegg). Den høyeste versjonen er den gjeldende; journalposten har dokumentstatus B til dokumentet er ferdigstilt';
