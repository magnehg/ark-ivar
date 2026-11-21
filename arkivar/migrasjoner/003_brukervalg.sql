-- Ark-Ivar: valgene en bruker har gjort i grensesnittet og som skal følge kontoen, ikke nettleseren (for eksempel
-- hvordan oversiktene vises). Kan kjøres flere ganger (IF NOT EXISTS). Endres aldri etter at den er lagt ut.

CREATE TABLE IF NOT EXISTS arkivar_brukervalg (
    bruker_id CHAR(36) NOT NULL,
    nokkel VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verdi VARCHAR(100) NOT NULL,
    endret DATETIME(6) NOT NULL,
    PRIMARY KEY (bruker_id, nokkel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Valg i grensesnittet som er lagret på brukeren (én rad per bruker og valg)';
