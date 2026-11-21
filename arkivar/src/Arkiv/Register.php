<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Db;
use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Psr\Clock\ClockInterface;

/**
 * Mappene og journalpostene i et øvingsarkiv (arkivar_sak og arkivar_journalpost). Alle oppslag tar arkivets ID, så
 * en bruker aldri kan lese eller endre noe i et annet arkiv.
 *
 * Saksnummeret er saksår og en fortløpende sekvens (2026/7), og hver journalpost har et dokumentnummer i saken
 * (2026/7-3) og et løpenummer i journalen. Tellerne står på arkivet.
 *
 * Et inngående dokument avskrives når det er besvart eller tatt til etterretning (arkivar_avskrivning). Et
 * journalført inngående dokument uten avskrivning er en restanse. Et utgående dokument studenten skriver som svar,
 * peker på dokumentet det besvarer (arkivar_svar), og avskriver det når svaret er ekspedert.
 *
 * @phpstan-type Henvisning array{id: string, sak_id: string, postnr: int, saksnummer: string}
 * @phpstan-type Koblinger array{
 *     avskrevet: array<string, array{mate: string, dato: string, av: ?string}>,
 *     svar: array<string, list<array{id: string, sak_id: string, postnr: int, saksnummer: string, avskriver: bool}>>,
 *     nummer: array<string, Henvisning>,
 * }
 */
final class Register
{
    /**
     * Kolonnene fra mappen som følger med en journalpost i listene på tvers av mapper (journalen, kontrolloppgavene
     * og restansene), der «j» er journalposten og «s» er mappen.
     */
    public const MED_SAK = 'j.*, s.saksaar, s.sekvens AS sakssekvens, s.tittel AS sakstittel, s.skjerm_tittel AS sak_skjermet, s.offentlig_tittel AS sak_offentlig_tittel,
        s.arkivdel AS sak_arkivdel, s.klasse AS sak_klasse, s.skjerm_klasse AS sak_skjerm_klasse, s.tilgangskode AS sak_tilgangskode, s.status AS sak_status';

    /** Vilkåret i SQL for en restanse, der «j» er journalposten (se kanVaereRestanse()). */
    private const RESTANSE = "j.type = 'I' AND j.status = 'J' AND j.journalfort = 1 AND NOT EXISTS (SELECT 1 FROM arkivar_avskrivning a WHERE a.journalpost_id = j.id)";

    /**
     * Avskrivningene og svarene i et arkiv, hentet én gang per forespørsel (se koblinger()).
     *
     * @var array<string, Koblinger>
     */
    private array $koblinger = [];

    public function __construct(private readonly Db $db, private readonly ClockInterface $klokke, private readonly Grunndata $grunndata)
    {
    }

    /**
     * Låser arkivet til transaksjonen er ferdig. To forespørsler som registrerer i samme arkiv samtidig (to faner,
     * dobbel innsending), går da etter hverandre, så sjekker og nummer ikke overlapper. Må kalles i en transaksjon.
     */
    public function laas(string $arkivId): void
    {
        $this->db->verdi('SELECT id FROM arkivar_arkiv WHERE id = ? FOR UPDATE', [$arkivId]);
    }

    /** Neste nummer i en av arkivets tellere (sak_sekvens eller journal_sekvens). */
    private function neste(string $arkivId, string $teller): int
    {
        $kolonne = $teller === 'sak' ? 'sak_sekvens' : 'journal_sekvens';
        $this->db->utfor("UPDATE arkivar_arkiv SET $kolonne = $kolonne + 1 WHERE id = ?", [$arkivId]);

        return Felt::tall($this->db->verdi("SELECT $kolonne FROM arkivar_arkiv WHERE id = ?", [$arkivId]));
    }

    /**
     * Oppretter en mappe og gir ID-en. $nokkel settes bare på mappene som følger med arkivet. Med $offentligTittel
     * er tittelen skjermet: den offentlige journalen viser den offentlige tittelen. $klasse er primærklasseringen i
     * arkivdelens ordning (arkivnøkkel, gårds- og bruksnummer, fødselsdato og navn, eller utvalg), og med
     * $skjermKlasse vises den ikke i den offentlige journalen. $tilgangskode gir hjemmelen for skjermingen. $mal er
     * saksmalen studenten valgte.
     */
    public function opprettSak(
        string $arkivId,
        string $tittel,
        string $arkivdel,
        string $klasse,
        string $enhet,
        string $saksansvarlig,
        string $saksdato,
        ?string $nokkel = null,
        ?string $offentligTittel = null,
        ?string $tilgangskode = null,
        string $status = 'B',
        bool $skjermKlasse = false,
        ?string $mal = null,
    ): string {
        $id = Tilfeldig::uuid();
        $this->db->utfor(
            'INSERT INTO arkivar_sak (id, arkiv_id, nokkel, saksaar, sekvens, tittel, offentlig_tittel, skjerm_tittel, tilgangskode, arkivdel, klasse, skjerm_klasse, mal,
                enhet, saksansvarlig, status, saksdato, opprettet)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, $arkivId, $nokkel, (int) substr($saksdato, 0, 4), $this->neste($arkivId, 'sak'), $tittel, $offentligTittel, $offentligTittel !== null ? 1 : 0, $tilgangskode,
                $arkivdel, $klasse, $skjermKlasse ? 1 : 0, $mal, $enhet, $saksansvarlig, $status, $saksdato, Db::tid($this->klokke->now()),
            ],
        );

        return $id;
    }

    /**
     * Oppretter en journalpost i saken og gir ID-en.
     *
     * @param array{
     *     type: string,
     *     status: string,
     *     tittel: string,
     *     offentlig_tittel?: ?string,
     *     dokumentdato: string,
     *     journaldato: string,
     *     saksbehandler: string,
     *     enhet: string,
     *     tilgangskode?: ?string,
     *     skjerm_tittel?: bool,
     *     skjerm_part?: bool,
     *     part_navn?: string,
     *     part_adresse?: ?string,
     *     journalfort?: bool,
     *     oppgave_id?: ?string,
     *     startdokument?: ?string,
     *     dokumentstatus?: ?string,
     *     variantformat?: ?string,
     * } $felt
     *
     * En journalpost med et dokument (fra postmottaket eller et som fulgte med) får status på dokumentfilen:
     * dokumentet er ferdigstilt, og det ligger i arkivformat når journalposten er arkivert, ellers i
     * produksjonsformat. En registrering uten dokument har ingen dokumentfil. Et dokument studenten skriver selv,
     * oppgir statusen på dokumentfilen (under redigering til det ferdigstilles).
     */
    public function opprettJournalpost(string $arkivId, string $sakId, array $felt): string
    {
        $id = Tilfeldig::uuid();
        // Løpenummeret først: oppdateringen av telleren låser arkivet, så dokumentnummeret i mappen leses etter låsen.
        $sekvens = $this->neste($arkivId, 'journal');
        $postnr = Felt::tall($this->db->verdi('SELECT COALESCE(MAX(postnr), 0) + 1 FROM arkivar_journalpost WHERE sak_id = ?', [$sakId]));
        $harDokument = ($felt['oppgave_id'] ?? null) !== null || ($felt['startdokument'] ?? null) !== null;
        $this->db->utfor(
            'INSERT INTO arkivar_journalpost (id, arkiv_id, sak_id, postnr, journalaar, sekvens, journalfort, type, status, tittel, offentlig_tittel, dokumentdato, journaldato,
                saksbehandler, enhet, tilgangskode, skjerm_tittel, skjerm_part, part_navn, part_adresse, oppgave_id, startdokument, dokumentstatus, variantformat, opprettet)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, $arkivId, $sakId, $postnr, (int) substr($felt['journaldato'], 0, 4), $sekvens,
                ($felt['journalfort'] ?? true) ? 1 : 0, $felt['type'], $felt['status'], $felt['tittel'], $felt['offentlig_tittel'] ?? null,
                $felt['dokumentdato'], $felt['journaldato'], $felt['saksbehandler'], $felt['enhet'], $felt['tilgangskode'] ?? null,
                ($felt['skjerm_tittel'] ?? false) ? 1 : 0, ($felt['skjerm_part'] ?? false) ? 1 : 0, $felt['part_navn'] ?? '', $felt['part_adresse'] ?? null,
                $felt['oppgave_id'] ?? null, $felt['startdokument'] ?? null,
                $felt['dokumentstatus'] ?? ($harDokument ? Kodelister::DOK_FERDIGSTILT : null),
                $felt['variantformat'] ?? ($harDokument ? Kodelister::variantformat($felt['status']) : null), Db::tid($this->klokke->now()),
            ],
        );

        return $id;
    }

    /**
     * Sakene i arkivet, nyeste først, med antall registreringer.
     *
     * @return list<array<string, mixed>>
     */
    public function saker(string $arkivId): array
    {
        return $this->db->alle(
            'SELECT s.*, (SELECT COUNT(*) FROM arkivar_journalpost j WHERE j.sak_id = s.id) AS antall
               FROM arkivar_sak s WHERE s.arkiv_id = ? ORDER BY s.saksaar DESC, s.sekvens DESC',
            [$arkivId],
        );
    }

    /**
     * Saksnumrene til mappene som fulgte med arkivet, etter nøkkelen. Dokumentene viser til dem («Vår ref.»).
     *
     * @return array<string, string>
     */
    public function saksnumre(string $arkivId): array
    {
        $ut = [];
        foreach ($this->db->alle('SELECT nokkel, saksaar, sekvens FROM arkivar_sak WHERE arkiv_id = ? AND nokkel IS NOT NULL', [$arkivId]) as $rad) {
            $ut[Felt::tekst($rad['nokkel'])] = Felt::tall($rad['saksaar']) . '/' . Felt::tall($rad['sekvens']);
        }

        return $ut;
    }

    /** @return array<string, mixed>|null */
    public function sak(string $arkivId, string $id): ?array
    {
        return $this->db->en('SELECT * FROM arkivar_sak WHERE arkiv_id = ? AND id = ?', [$arkivId, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function journalposter(string $arkivId, string $sakId): array
    {
        return $this->db->alle('SELECT * FROM arkivar_journalpost WHERE arkiv_id = ? AND sak_id = ? ORDER BY postnr', [$arkivId, $sakId]);
    }

    /** @return array<string, mixed>|null */
    public function journalpost(string $arkivId, string $id): ?array
    {
        return $this->db->en('SELECT * FROM arkivar_journalpost WHERE arkiv_id = ? AND id = ?', [$arkivId, $id]);
    }

    /**
     * Journalen: alle journalførte poster, nyeste først, med saken de ligger i. Poster som er arkivert uten
     * journalføring, står ikke i journalen.
     *
     * @return list<array<string, mixed>>
     */
    public function journal(string $arkivId): array
    {
        return $this->db->alle(
            'SELECT ' . self::MED_SAK . ' FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id
              WHERE j.arkiv_id = ? AND j.journalfort = 1 ORDER BY j.journaldato DESC, j.sekvens DESC',
            [$arkivId],
        );
    }

    /**
     * Restansene: journalførte inngående dokumenter som ikke er avskrevet, eldst først, med mappen de ligger i.
     * Med $sakId gis bare restansene i den mappen.
     *
     * @return list<array<string, mixed>>
     */
    public function restanser(string $arkivId, ?string $sakId = null): array
    {
        return $this->db->alle(
            'SELECT ' . self::MED_SAK . ' FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id
              WHERE j.arkiv_id = ? AND ' . self::RESTANSE . ($sakId !== null ? ' AND j.sak_id = ?' : '') . ' ORDER BY j.journaldato, j.sekvens',
            $sakId !== null ? [$arkivId, $sakId] : [$arkivId],
        );
    }

    public function antallRestanser(string $arkivId): int
    {
        return Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_journalpost j WHERE j.arkiv_id = ? AND ' . self::RESTANSE, [$arkivId]));
    }

    /**
     * Om journalposten er en restanse når den ikke er avskrevet: et inngående dokument som er journalført. Før
     * journalføringen står det til journalføring, og når mappen er avsluttet, er det arkivert.
     *
     * @param array<string, mixed> $rad raden fra arkivar_journalpost
     */
    public static function kanVaereRestanse(array $rad): bool
    {
        return Felt::tekst($rad['type']) === 'I' && Felt::tekst($rad['status']) === Kodelister::JP_JOURNALFORT && Felt::tall($rad['journalfort']) === 1;
    }

    /**
     * Avskrivningen av et dokument, eller null når det ikke er avskrevet.
     *
     * @return array{mate: string, dato: string, av: ?string}|null
     */
    public function avskrivning(string $arkivId, string $journalpostId): ?array
    {
        $rad = $this->db->en('SELECT mate, dato, avskrevet_av FROM arkivar_avskrivning WHERE arkiv_id = ? AND journalpost_id = ?', [$arkivId, $journalpostId]);

        return $rad === null ? null : ['mate' => Felt::tekst($rad['mate']), 'dato' => Felt::tekst($rad['dato']), 'av' => Felt::valgfriTekst($rad['avskrevet_av'])];
    }

    /** Avskriver et dokument. $avskrevetAv er journalposten som besvarer det (avskrivningsmåte BU). */
    public function avskriv(string $arkivId, string $journalpostId, string $mate, string $dato, ?string $avskrevetAv = null): void
    {
        $this->db->utfor(
            'INSERT INTO arkivar_avskrivning (journalpost_id, arkiv_id, mate, dato, avskrevet_av, opprettet) VALUES (?, ?, ?, ?, ?, ?)',
            [$journalpostId, $arkivId, $mate, $dato, $avskrevetAv, Db::tid($this->klokke->now())],
        );
        unset($this->koblinger[$arkivId]);
    }

    public function fjernAvskrivning(string $arkivId, string $journalpostId): void
    {
        $this->db->utfor('DELETE FROM arkivar_avskrivning WHERE arkiv_id = ? AND journalpost_id = ?', [$arkivId, $journalpostId]);
        unset($this->koblinger[$arkivId]);
    }

    /** Sier at journalposten er et svar på et inngående dokument. */
    public function settSvar(string $arkivId, string $journalpostId, string $svarPaa): void
    {
        $this->db->utfor('INSERT INTO arkivar_svar (journalpost_id, arkiv_id, svar_paa) VALUES (?, ?, ?)', [$journalpostId, $arkivId, $svarPaa]);
        unset($this->koblinger[$arkivId]);
    }

    /** Dokumentet journalposten er et svar på, eller null. */
    public function svarPaa(string $arkivId, string $journalpostId): ?string
    {
        return Felt::valgfriTekst($this->db->verdi('SELECT svar_paa FROM arkivar_svar WHERE arkiv_id = ? AND journalpost_id = ?', [$arkivId, $journalpostId]));
    }

    /**
     * Avskrivningene og svarene i arkivet: hvordan hvert avskrevne dokument er avskrevet, hvilke dokumenter hvert
     * svar besvarer (og om svaret er det som avskriver dem), og numrene til journalpostene det vises til. Hentes én
     * gang per forespørsel, så en liste med mange journalposter ikke gir ett oppslag per rad.
     *
     * @return Koblinger
     */
    private function koblinger(string $arkivId): array
    {
        if (isset($this->koblinger[$arkivId])) {
            return $this->koblinger[$arkivId];
        }
        $avskrevet = [];
        // Per svar: dokumentene det besvarer, og om svaret er det som avskriver dem.
        $par = [];
        foreach ($this->db->alle('SELECT journalpost_id, mate, dato, avskrevet_av FROM arkivar_avskrivning WHERE arkiv_id = ?', [$arkivId]) as $rad) {
            $id = Felt::tekst($rad['journalpost_id']);
            $av = Felt::valgfriTekst($rad['avskrevet_av']);
            $avskrevet[$id] = ['mate' => Felt::tekst($rad['mate']), 'dato' => Felt::tekst($rad['dato']), 'av' => $av];
            if ($av !== null) {
                $par[$av][$id] = true;
            }
        }
        foreach ($this->db->alle('SELECT journalpost_id, svar_paa FROM arkivar_svar WHERE arkiv_id = ?', [$arkivId]) as $rad) {
            // Et foreløpig svar, og et svar som ikke er ekspedert ennå, avskriver ikke.
            $par[Felt::tekst($rad['journalpost_id'])][Felt::tekst($rad['svar_paa'])] ??= false;
        }
        $ider = [];
        foreach ($par as $svarId => $besvarte) {
            $ider[] = $svarId;
            foreach (array_keys($besvarte) as $id) {
                $ider[] = $id;
            }
        }
        $ider = array_values(array_unique($ider));
        $nummer = [];
        if ($ider !== []) {
            $rader = $this->db->alle(
                'SELECT j.id, j.sak_id, j.postnr, s.saksaar, s.sekvens FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id
                  WHERE j.arkiv_id = ? AND j.id IN (' . implode(', ', array_fill(0, count($ider), '?')) . ')',
                [$arkivId, ...$ider],
            );
            foreach ($rader as $rad) {
                $nummer[Felt::tekst($rad['id'])] = [
                    'id' => Felt::tekst($rad['id']), 'sak_id' => Felt::tekst($rad['sak_id']), 'postnr' => Felt::tall($rad['postnr']),
                    'saksnummer' => Felt::tall($rad['saksaar']) . '/' . Felt::tall($rad['sekvens']),
                ];
            }
        }
        $svar = [];
        foreach ($par as $svarId => $besvarte) {
            $liste = [];
            foreach ($besvarte as $id => $avskriver) {
                if (isset($nummer[$id])) {
                    $liste[] = $nummer[$id] + ['avskriver' => $avskriver];
                }
            }
            usort($liste, static fn (array $a, array $b): int => [$a['saksnummer'], $a['postnr']] <=> [$b['saksnummer'], $b['postnr']]);
            $svar[$svarId] = $liste;
        }

        return $this->koblinger[$arkivId] = ['avskrevet' => $avskrevet, 'svar' => $svar, 'nummer' => $nummer];
    }

    public function slettJournalpost(string $arkivId, string $id): void
    {
        // Dokumentet studenten har skrevet, følger journalposten. Det gjør også avskrivningen og merknadene. Var
        // journalposten svaret som avskrev et annet dokument, forsvinner koblingen: det andre er igjen en restanse.
        $this->db->utfor('DELETE FROM arkivar_dokument WHERE arkiv_id = ? AND journalpost_id = ?', [$arkivId, $id]);
        $this->db->utfor('DELETE FROM arkivar_avskrivning WHERE arkiv_id = ? AND (journalpost_id = ? OR avskrevet_av = ?)', [$arkivId, $id, $id]);
        $this->db->utfor('DELETE FROM arkivar_svar WHERE arkiv_id = ? AND (journalpost_id = ? OR svar_paa = ?)', [$arkivId, $id, $id]);
        $this->db->utfor('DELETE FROM arkivar_merknad WHERE arkiv_id = ? AND journalpost_id = ?', [$arkivId, $id]);
        unset($this->koblinger[$arkivId]);
        $this->db->utfor('DELETE FROM arkivar_journalpost WHERE arkiv_id = ? AND id = ?', [$arkivId, $id]);
    }

    /**
     * Flytter journalposten til en annen mappe. Den får neste ledige dokumentnummer der og beholder løpenummeret.
     * Gir det nye dokumentnummeret. Må kalles i en transaksjon med arkivet låst.
     */
    public function flyttJournalpost(string $arkivId, string $id, string $tilSakId): int
    {
        $postnr = Felt::tall($this->db->verdi('SELECT COALESCE(MAX(postnr), 0) + 1 FROM arkivar_journalpost WHERE sak_id = ?', [$tilSakId]));
        $this->db->utfor('UPDATE arkivar_journalpost SET sak_id = ?, postnr = ? WHERE arkiv_id = ? AND id = ?', [$tilSakId, $postnr, $arkivId, $id]);
        unset($this->koblinger[$arkivId]);

        return $postnr;
    }

    /**
     * Endrer registreringsfeltene på en journalpost: mens dokumentet er under arbeid, og når registreringen rettes.
     *
     * @param array{tittel: string, offentlig_tittel: ?string, dokumentdato: string, tilgangskode: ?string, skjerm_tittel: bool, skjerm_part: bool, type: string, part_navn: string} $felt
     */
    public function oppdaterJournalpost(string $arkivId, string $id, array $felt): void
    {
        $this->db->utfor(
            'UPDATE arkivar_journalpost SET type = ?, tittel = ?, offentlig_tittel = ?, dokumentdato = ?, tilgangskode = ?, skjerm_tittel = ?, skjerm_part = ?, part_navn = ?
              WHERE arkiv_id = ? AND id = ?',
            [
                $felt['type'], $felt['tittel'], $felt['offentlig_tittel'], $felt['dokumentdato'], $felt['tilgangskode'],
                $felt['skjerm_tittel'] ? 1 : 0, $felt['skjerm_part'] ? 1 : 0, $felt['part_navn'], $arkivId, $id,
            ],
        );
    }

    /**
     * Ferdigstiller dokumentet i en journalpost: dokumentfilen er ferdigstilt, og journalposten får statusen
     * saksbehandleren setter når dokumentet er ferdig (ekspedert, ferdigstilt eller midlertidig registrert). Den
     * står ikke i journalen før arkivtjenesten har journalført den.
     */
    public function ferdigstillJournalpost(string $arkivId, string $id, string $status): void
    {
        $this->db->utfor(
            'UPDATE arkivar_journalpost SET status = ?, journalfort = 0, dokumentstatus = ?, variantformat = ? WHERE arkiv_id = ? AND id = ?',
            [$status, Kodelister::DOK_FERDIGSTILT, Kodelister::variantformat($status), $arkivId, $id],
        );
    }

    /**
     * Setter journalstatusen. $journalfort sier om journalposten står i journalen, og $journaldato settes når den
     * journalføres. Dokumentfilen får variantformatet som hører til statusen.
     */
    public function settJournalstatus(string $arkivId, string $id, string $status, bool $journalfort, string $journaldato): void
    {
        $this->db->utfor(
            'UPDATE arkivar_journalpost SET status = ?, journalfort = ?, journaldato = ?, variantformat = CASE WHEN dokumentstatus IS NULL THEN NULL ELSE ? END
              WHERE arkiv_id = ? AND id = ?',
            [$status, $journalfort ? 1 : 0, $journaldato, Kodelister::variantformat($status), $arkivId, $id],
        );
    }

    /** Arkiverer de journalførte dokumentene i en mappe som avsluttes. */
    public function arkiverJournalposter(string $arkivId, string $sakId): void
    {
        $this->db->utfor(
            'UPDATE arkivar_journalpost SET status = ?, variantformat = CASE WHEN dokumentstatus IS NULL THEN NULL ELSE ? END WHERE arkiv_id = ? AND sak_id = ? AND status = ?',
            [Kodelister::JP_ARKIVERT, Kodelister::VARIANT_ARKIV, $arkivId, $sakId, Kodelister::JP_JOURNALFORT],
        );
    }

    public function settSaksstatus(string $arkivId, string $sakId, string $status): void
    {
        $this->db->utfor('UPDATE arkivar_sak SET status = ? WHERE arkiv_id = ? AND id = ?', [$status, $arkivId, $sakId]);
    }

    /** Sletter en mappe studenten har opprettet selv, når den er tom. Gir om den ble slettet. */
    public function slettTomSak(string $arkivId, string $sakId): bool
    {
        return $this->db->utfor(
            'DELETE FROM arkivar_sak WHERE arkiv_id = ? AND id = ? AND nokkel IS NULL AND NOT EXISTS (SELECT 1 FROM arkivar_journalpost j WHERE j.sak_id = arkivar_sak.id)',
            [$arkivId, $sakId],
        ) > 0;
    }

    /**
     * Dokumentene fra postmottaket som er registrert i mappen (ID-ene til oppgavene), uten $unntatt.
     *
     * @return list<string>
     */
    public function oppgaverISak(string $arkivId, string $sakId, ?string $unntatt = null): array
    {
        $ut = [];
        foreach ($this->db->alle('SELECT oppgave_id FROM arkivar_journalpost WHERE arkiv_id = ? AND sak_id = ? AND oppgave_id IS NOT NULL', [$arkivId, $sakId]) as $rad) {
            $id = Felt::tekst($rad['oppgave_id']);
            if ($id !== $unntatt) {
                $ut[] = $id;
            }
        }

        return $ut;
    }

    /**
     * Søk i mappene: alle ordene i søket må finnes i mappen (saksnummeret, tittelen, klasseringen eller
     * arkivdelen) eller i dokumentene som ligger i den (tittel og avsender eller mottaker). Store og små bokstaver
     * teller ikke. Mapper der selve mappen har alle ordene, kommer først; ellers nyeste først. Hvert treff har
     * mappen og høyst tre dokumenter som har et av ordene.
     *
     * @return list<array{sak: array<string, mixed>, journalposter: list<array<string, mixed>>}>
     */
    public function sok(string $arkivId, string $sok): array
    {
        $liten = static fn (string $tekst): string => mb_strtolower($tekst, 'UTF-8');
        if (!mb_check_encoding($sok, 'UTF-8')) {
            return [];
        }
        // Tegnsetting rundt et ord teller ikke («Måke,» og «"elevmappe"»), og et ord uten bokstaver eller tall (en
        // bindestrek for seg selv) er ikke et søkeord. Tegn inne i ordet står: «9/112» og «12.11.2026».
        $ord = [];
        $deler = preg_split('/\s+/u', trim($liten(mb_substr($sok, 0, 200, 'UTF-8'))), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($deler === false ? [] : $deler as $del) {
            $del = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $del);
            if ($del !== '') {
                $ord[] = $del;
            }
        }
        if (mb_strlen(implode('', $ord), 'UTF-8') < 2) {
            return [];
        }
        $poster = [];
        foreach ($this->db->alle('SELECT sak_id, postnr, tittel, part_navn FROM arkivar_journalpost WHERE arkiv_id = ? ORDER BY postnr', [$arkivId]) as $post) {
            $poster[Felt::tekst($post['sak_id'])][] = $post;
        }
        $harAlle = static function (string $tekst) use ($ord): bool {
            foreach ($ord as $o) {
                if (!str_contains($tekst, $o)) {
                    return false;
                }
            }

            return true;
        };
        $treff = [];
        foreach ($this->saker($arkivId) as $rad) {
            $sak = $this->visSak($rad);
            $egen = $liten(implode(' ', [Felt::tekst($sak['saksnummer']), Felt::tekst($sak['tittel']), Felt::tekst($sak['klasse_tekst']), Felt::tekst($sak['arkivdel_tekst'])]));
            $dokumenter = $poster[Felt::tekst($sak['id'])] ?? [];
            $alt = $egen;
            foreach ($dokumenter as $post) {
                $alt .= ' ' . $liten(Felt::tekst($post['tittel']) . ' ' . Felt::tekst($post['part_navn']));
            }
            if (!$harAlle($alt)) {
                continue;
            }
            $viste = [];
            foreach ($dokumenter as $post) {
                $tekst = $liten(Felt::tekst($post['tittel']) . ' ' . Felt::tekst($post['part_navn']));
                foreach ($ord as $o) {
                    if (str_contains($tekst, $o) && !str_contains($egen, $o)) {
                        $viste[] = ['postnr' => Felt::tall($post['postnr']), 'tittel' => Felt::tekst($post['tittel']), 'part_navn' => Felt::tekst($post['part_navn'])];
                        break;
                    }
                }
            }
            $treff[] = ['sak' => $sak, 'journalposter' => array_slice($viste, 0, 3), 'egen' => $harAlle($egen)];
        }
        // saker() gir nyeste først, og usort er stabil: mappene der selve mappen har alle ordene, flyttes foran.
        usort($treff, static fn (array $a, array $b): int => (int) $b['egen'] <=> (int) $a['egen']);

        return array_map(static fn (array $t): array => ['sak' => $t['sak'], 'journalposter' => $t['journalposter']], $treff);
    }

    /**
     * Saken slik den sendes til nettleseren, med navnene ved siden av kodene.
     *
     * @param array<string, mixed> $rad
     * @return array<string, mixed>
     */
    public function visSak(array $rad): array
    {
        $ansvarlig = $this->grunndata->ansatt(Felt::tekst($rad['saksansvarlig']));

        return [
            'id' => Felt::tekst($rad['id']),
            'saksnummer' => Felt::tall($rad['saksaar']) . '/' . Felt::tall($rad['sekvens']),
            'tittel' => Felt::tekst($rad['tittel']),
            'skjerm_tittel' => Felt::tall($rad['skjerm_tittel']) === 1,
            'offentlig_tittel' => Felt::valgfriTekst($rad['offentlig_tittel']),
            'arkivdel' => Felt::tekst($rad['arkivdel']),
            'arkivdel_tekst' => $this->grunndata->arkivdeltekst(Felt::tekst($rad['arkivdel'])),
            'klasse' => Felt::tekst($rad['klasse']),
            'klasse_tekst' => $this->grunndata->klassetekst(Felt::tekst($rad['arkivdel']), Felt::tekst($rad['klasse'])),
            'skjerm_klasse' => Felt::tall($rad['skjerm_klasse']) === 1,
            'tilgangskode' => Felt::valgfriTekst($rad['tilgangskode']),
            'hjemmel' => $this->grunndata->tilgangstekst(Felt::valgfriTekst($rad['tilgangskode'])),
            'mal' => Felt::valgfriTekst($rad['mal']),
            'enhet' => Felt::tekst($rad['enhet']),
            'saksansvarlig' => Felt::tekst($rad['saksansvarlig']),
            'saksansvarlig_navn' => $ansvarlig['navn'] ?? Felt::tekst($rad['saksansvarlig']),
            'status' => Felt::tekst($rad['status']),
            'saksdato' => Felt::tekst($rad['saksdato']),
            'fulgte_med' => $rad['nokkel'] !== null,
            'antall' => Felt::tall($rad['antall'] ?? 0),
        ];
    }

    /** Hele dager fra en dato til en senere (0 når den ikke er senere). */
    public static function dagerSiden(string $fra, string $til): int
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $fra);
        $slutt = \DateTimeImmutable::createFromFormat('!Y-m-d', $til);
        if ($start === false || $slutt === false || $slutt <= $start) {
            return 0;
        }

        return $start->diff($slutt)->days;
    }

    /**
     * Løpenummeret til en journalpost slik det skrives i journalen: nummeret i året først og så året, «104/2026».
     * (Saksnummeret skrives motsatt vei, med året først.)
     */
    public static function lopenummer(int $aar, int $sekvens): string
    {
        return $sekvens . '/' . $aar;
    }

    /**
     * Journalposten slik den sendes til nettleseren. Med $offentlig vises den som i den offentlige journalen:
     * skjermet tittel, skjermet sakstittel og skjermet avsender eller mottaker er byttet med den offentlige
     * tittelen og *****.
     *
     * @param array<string, mixed> $rad
     * @return array<string, mixed>
     */
    public function visJournalpost(array $rad, bool $offentlig = false): array
    {
        $skjermTittel = Felt::tall($rad['skjerm_tittel']) === 1;
        $skjermPart = Felt::tall($rad['skjerm_part']) === 1;
        $offentligTittel = Felt::valgfriTekst($rad['offentlig_tittel']);
        $saksaar = Felt::tall($rad['saksaar'] ?? 0);
        $ut = [
            'id' => Felt::tekst($rad['id']),
            'sak_id' => Felt::tekst($rad['sak_id']),
            'postnr' => Felt::tall($rad['postnr']),
            'lopenummer' => self::lopenummer(Felt::tall($rad['journalaar']), Felt::tall($rad['sekvens'])),
            'journalfort' => Felt::tall($rad['journalfort']) === 1,
            'type' => Felt::tekst($rad['type']),
            'status' => Felt::tekst($rad['status']),
            'tittel' => $offentlig && $skjermTittel ? ($offentligTittel ?? '*****') : Felt::tekst($rad['tittel']),
            'offentlig_tittel' => $offentligTittel,
            'dokumentdato' => Felt::tekst($rad['dokumentdato']),
            'journaldato' => Felt::tekst($rad['journaldato']),
            'saksbehandler' => Felt::tekst($rad['saksbehandler']),
            'saksbehandler_navn' => $this->grunndata->ansatt(Felt::tekst($rad['saksbehandler']))['navn'] ?? Felt::tekst($rad['saksbehandler']),
            'enhet' => Felt::tekst($rad['enhet']),
            'tilgangskode' => Felt::valgfriTekst($rad['tilgangskode']),
            'hjemmel' => $this->grunndata->tilgangstekst(Felt::valgfriTekst($rad['tilgangskode'])),
            'skjerm_tittel' => $skjermTittel,
            'skjerm_part' => $skjermPart,
            'part_navn' => $offentlig && $skjermPart ? '*****' : Felt::tekst($rad['part_navn']),
            // Dokumentet kan åpnes i arkivet, men aldri fra den offentlige journalen.
            'har_dokument' => !$offentlig && ($rad['oppgave_id'] !== null || $rad['startdokument'] !== null),
            // Dokumentet fra postmottaket registreringen gjelder. En registrering uten dokument fra postmottaket som
            // studenten har laget selv, kan slettes.
            // Statusen på dokumentfilen (dokumentstatus og variantformat). Den offentlige journalen har ikke dokumentene.
            'dokumentstatus' => $offentlig ? null : Felt::valgfriTekst($rad['dokumentstatus'] ?? null),
            // Variantformatet følger statusen, også for rader som ble lagret før arkivformatet kom ved journalføring.
            'variantformat' => $offentlig || ($rad['variantformat'] ?? null) === null ? null : Kodelister::variantformat(Felt::tekst($rad['status'])),
            'oppgave' => $offentlig ? null : Felt::valgfriTekst($rad['oppgave_id']),
            'kan_slettes' => !$offentlig && $rad['oppgave_id'] === null && $rad['startdokument'] === null,
            // Avskrivningen (måten, datoen og journalposten som besvarer dokumentet), dokumentene denne journalposten
            // er svar på, og om den er en restanse. Den offentlige journalen har ikke dette.
            'avskrivning' => null,
            'svar_paa' => [],
            'restanse' => false,
            'restanse_dager' => null,
        ];
        if (!$offentlig) {
            $koblinger = $this->koblinger(Felt::tekst($rad['arkiv_id']));
            $avskrivning = $koblinger['avskrevet'][$ut['id']] ?? null;
            if ($avskrivning !== null) {
                $ut['avskrivning'] = ['mate' => $avskrivning['mate'], 'dato' => $avskrivning['dato'], 'av' => $avskrivning['av'] !== null ? ($koblinger['nummer'][$avskrivning['av']] ?? null) : null];
            } elseif (self::kanVaereRestanse($rad)) {
                $ut['restanse'] = true;
                $ut['restanse_dager'] = self::dagerSiden($ut['journaldato'], Dato::idag($this->klokke));
            }
            $ut['svar_paa'] = $koblinger['svar'][$ut['id']] ?? [];
        }
        if ($saksaar > 0) {
            $ut['saksnummer'] = $saksaar . '/' . Felt::tall($rad['sakssekvens'] ?? 0);
            // En skjermet sakstittel vises aldri i den offentlige journalen, heller ikke på en åpen journalpost.
            $sakSkjermet = Felt::tall($rad['sak_skjermet'] ?? 0) === 1;
            $ut['sakstittel'] = $offentlig && $sakSkjermet ? (Felt::valgfriTekst($rad['sak_offentlig_tittel'] ?? null) ?? '*****') : Felt::tekst($rad['sakstittel'] ?? '');
            // Klasseringen til mappen. Er den skjermet (fødselsdato og navn), vises den ikke i den offentlige journalen.
            $klasseSkjermet = Felt::tall($rad['sak_skjerm_klasse'] ?? 0) === 1;
            $ut['klasse_tekst'] = $offentlig && $klasseSkjermet ? '*****' : $this->grunndata->klassetekst(Felt::tekst($rad['sak_arkivdel'] ?? ''), Felt::tekst($rad['sak_klasse'] ?? ''));
            $ut['skjerm_klasse'] = $klasseSkjermet;
            // Hjemmelen for skjermingen på mappen. Den må stå i journalen også når journalposten selv er åpen.
            $ut['sak_skjermet'] = $sakSkjermet;
            $ut['sak_hjemmel'] = $sakSkjermet || $klasseSkjermet ? $this->grunndata->tilgangstekst(Felt::valgfriTekst($rad['sak_tilgangskode'] ?? null)) : '';
        }

        return $ut;
    }
}
