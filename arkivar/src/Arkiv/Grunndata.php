<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Felt;

/**
 * Grunndataene for Sirevåg kommune (arkivar/data/sirevag.json): arkivdelene, arkivnøkkelen, registrene (eiendommer,
 * ansatte, elever, barnehagebarn og utvalg), enhetene, de ansatte, tilgangskodene og mappene med dokumenter som
 * ligger i arkivet når en student får det. Dataene er felles for alle øvingsarkivene og kan ikke endres av
 * studentene.
 *
 * Hver arkivdel har en ordning som sier hva primærklasseringen til mappene er:
 *   emne     en klasse i arkivnøkkelen (sakarkivet). Arkivnøkkelen er fiktiv og bygger på statens fellesnøkkel.
 *   eiendom  gårds- og bruksnummer fra eiendomsregisteret (eiendomsarkivet)
 *   person   fødselsdato og navn fra et personregister (personalarkivet, elevarkivet, barnehagebarnarkivet)
 *   utvalg   det politiske utvalget (møtearkivet)
 * Personene i registrene har også et fødselsnummer. Det er oppdiktet og har feil kontrollsifre, så det kan ikke
 * tilhøre noen (testen sjekker det).
 *
 * Saksmalene er forslagene studenten velger mellom når en mappe opprettes: arkivdelen og et forslag til tittel er
 * fylt inn, og malen har et søketips til å finne ut om mappen finnes fra før.
 *
 * @phpstan-type Arkivdel array{kode: string, tittel: string, ordning: string, mappe: string, beskrivelse: string}
 * @phpstan-type Klasse array{kode: string, tittel: string, forelder: ?string, merknad: ?string, henvisning: ?string}
 * @phpstan-type Registerpost array{kode: string, tittel: string, detalj: string, fodt: ?string, fnr: ?string}
 * @phpstan-type Saksmal array{kode: string, navn: string, arkivdel: string, tittel: string, beskrivelse: string, soketips: string}
 * @phpstan-type Enhet array{kode: string, navn: string}
 * @phpstan-type Ansatt array{initialer: string, navn: string, tittel: string, enhet: string}
 * @phpstan-type Tilgangskode array{kode: string, navn: string, hjemmel: string}
 * @phpstan-type Dokument array{
 *     kanal: string,
 *     dokumentdato: string,
 *     mottatt: ?string,
 *     avsender: array{navn: string, adresse: ?string, epost: ?string},
 *     mottaker: ?string,
 *     emne: string,
 *     tekst: list<string>,
 *     vedlegg: list<string>,
 *     utkast: list<string>,
 * }
 * @phpstan-type Startpost array{
 *     nr: int,
 *     type: string,
 *     tittel: string,
 *     offentlig_tittel: ?string,
 *     dokumentdato: string,
 *     journaldato: string,
 *     part: string,
 *     skjerm_part: bool,
 *     status: string,
 *     journalfort: bool,
 *     tilgangskode: ?string,
 *     dokument: ?Dokument,
 * }
 * @phpstan-type Startsak array{
 *     nokkel: string,
 *     tittel: string,
 *     offentlig_tittel: ?string,
 *     tilgangskode: ?string,
 *     arkivdel: string,
 *     klasse: string,
 *     skjerm_klasse: bool,
 *     enhet: string,
 *     saksansvarlig: string,
 *     saksdato: string,
 *     status: string,
 *     journalposter: list<Startpost>,
 * }
 */
final class Grunndata
{
    public const EMNE = 'emne';
    public const EIENDOM = 'eiendom';
    public const PERSON = 'person';
    public const UTVALG = 'utvalg';
    public const ORDNINGER = [self::EMNE, self::EIENDOM, self::PERSON, self::UTVALG];

    /** @var array<string, mixed> */
    private array $data;

    /** @var list<Startsak>|null */
    private ?array $saker = null;

    /** @var list<Klasse>|null */
    private ?array $klasser = null;

    private readonly string $fil;

    public function __construct(?string $fil = null)
    {
        $this->fil = $fil ?? dirname(__DIR__, 2) . '/data/sirevag.json';
        $this->data = self::les($this->fil);
    }

    /** Utgaven av grunndataene: et fingeravtrykk av filen. Endres filen, endres utgaven. */
    public function utgave(): string
    {
        return self::fingeravtrykk($this->fil);
    }

    public static function fingeravtrykk(string $fil): string
    {
        $hash = hash_file('sha256', $fil);

        return $hash === false ? '' : $hash;
    }

    /**
     * Leser en JSON-fil med et objekt øverst.
     *
     * @return array<string, mixed>
     */
    public static function les(string $fil): array
    {
        $json = json_decode((string) file_get_contents($fil), true, 64, JSON_THROW_ON_ERROR);
        $ut = [];
        foreach (is_array($json) ? $json : [] as $nokkel => $verdi) {
            $ut[(string) $nokkel] = $verdi;
        }

        return $ut;
    }

    /**
     * Radene under en nøkkel, som matriser med tekstnøkler.
     *
     * @param array<array-key, mixed> $kilde
     * @return list<array<string, mixed>>
     */
    public static function rader(array $kilde, string $nokkel): array
    {
        $ut = [];
        foreach (is_array($kilde[$nokkel] ?? null) ? $kilde[$nokkel] : [] as $rad) {
            $ny = [];
            foreach (is_array($rad) ? $rad : [] as $felt => $verdi) {
                $ny[(string) $felt] = $verdi;
            }
            $ut[] = $ny;
        }

        return $ut;
    }

    /**
     * Tekstene i en liste (andre verdier hoppes over).
     *
     * @return list<string>
     */
    public static function tekster(mixed $verdi): array
    {
        $ut = [];
        foreach (is_array($verdi) ? $verdi : [] as $tekst) {
            if (is_string($tekst)) {
                $ut[] = $tekst;
            }
        }

        return $ut;
    }

    /**
     * Et dokument (brev, e-post, notat, saksframlegg, protokoll) i den felles formen for arket i grensesnittet.
     *
     * @param array<array-key, mixed> $d
     * @return Dokument
     */
    public static function dokument(array $d, string $dokumentdato): array
    {
        $avsender = is_array($d['avsender'] ?? null) ? $d['avsender'] : [];

        return [
            'kanal' => Felt::tekst($d['kanal'] ?? null, 'brev'),
            'dokumentdato' => Felt::tekst($d['dokumentdato'] ?? null, $dokumentdato),
            'mottatt' => Felt::valgfriTekst($d['mottatt'] ?? null),
            'avsender' => [
                'navn' => Felt::tekst($avsender['navn'] ?? null),
                'adresse' => Felt::valgfriTekst($avsender['adresse'] ?? null),
                'epost' => Felt::valgfriTekst($avsender['epost'] ?? null),
            ],
            'mottaker' => Felt::valgfriTekst($d['mottaker'] ?? null),
            'emne' => Felt::tekst($d['emne'] ?? null),
            'tekst' => self::tekster($d['tekst'] ?? null),
            'vedlegg' => self::tekster($d['vedlegg'] ?? null),
            // Saksbehandlerens første utkast, for dokumentene som har to versjoner.
            'utkast' => self::tekster($d['utkast'] ?? null),
        ];
    }

    /**
     * Setter saksnumrene inn i et dokument. I tekstene står «{{sak}}» for mappen dokumentet ligger i og
     * «{{sak:<nøkkel>}}» for en annen mappe som fulgte med arkivet; numrene finnes først når arkivet er lagd, og er
     * ulike fra arkiv til arkiv. En mappe som ikke finnes, gir tom tekst.
     *
     * @param array<string, mixed> $dokument
     * @param array<string, string> $saksnumre saksnummer etter mappens nøkkel
     * @return array<string, mixed>
     */
    public static function medSaksnumre(array $dokument, array $saksnumre, ?string $denne = null): array
    {
        $bytt = static fn (string $tekst): string => (string) preg_replace_callback(
            '/\{\{sak(?::([a-z0-9-]+))?\}\}/',
            static fn (array $treff): string => $saksnumre[$treff[1] ?? $denne ?? ''] ?? '',
            $tekst,
        );
        foreach (['emne', 'tekst', 'vedlegg', 'utkast'] as $felt) {
            $verdi = $dokument[$felt] ?? null;
            if (is_string($verdi)) {
                $dokument[$felt] = $bytt($verdi);
            } elseif (is_array($verdi)) {
                $dokument[$felt] = array_map(static fn (mixed $v): mixed => is_string($v) ? $bytt($v) : $v, $verdi);
            }
        }

        return $dokument;
    }

    /** @return array{navn: string, adresse: string, epost: string} */
    public function virksomhet(): array
    {
        $v = is_array($this->data['virksomhet'] ?? null) ? $this->data['virksomhet'] : [];

        return ['navn' => Felt::tekst($v['navn'] ?? null), 'adresse' => Felt::tekst($v['adresse'] ?? null), 'epost' => Felt::tekst($v['epost'] ?? null)];
    }

    /** @return list<Arkivdel> */
    public function arkivdeler(): array
    {
        return array_map(
            static fn (array $r): array => [
                'kode' => Felt::tekst($r['kode'] ?? null),
                'tittel' => Felt::tekst($r['tittel'] ?? null),
                'ordning' => Felt::tekst($r['ordning'] ?? null, self::EMNE),
                'mappe' => Felt::tekst($r['mappe'] ?? null),
                'beskrivelse' => Felt::tekst($r['beskrivelse'] ?? null),
            ],
            self::rader($this->data, 'arkivdeler'),
        );
    }

    /** @return Arkivdel|null */
    public function arkivdel(string $kode): ?array
    {
        foreach ($this->arkivdeler() as $a) {
            if ($a['kode'] === $kode) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Arkivnøkkelen: klassene i sakarkivet. Klasse 0–2 (egenforvaltningen) følger oppbygningen i statens
     * fellesnøkkel, klasse 3–8 er kommunens fagområder. En klasse med henvisning viser til arkivdelen der mappene
     * ligger (personalmappene i personalarkivet og så videre), og kan ikke brukes på en mappe i sakarkivet.
     *
     * @return list<Klasse>
     */
    public function klasser(): array
    {
        return $this->klasser ??= array_map(
            static fn (array $r): array => [
                'kode' => Felt::tekst($r['kode'] ?? null),
                'tittel' => Felt::tekst($r['tittel'] ?? null),
                'forelder' => Felt::valgfriTekst($r['forelder'] ?? null),
                'merknad' => Felt::valgfriTekst($r['merknad'] ?? null),
                'henvisning' => Felt::valgfriTekst($r['henvisning'] ?? null),
            ],
            self::rader($this->data, 'klasser'),
        );
    }

    /**
     * Registeret mappene i en arkivdel klassifiseres på: eiendommene, de ansatte, elevene, barnehagebarna eller
     * utvalgene. Sakarkivet har ikke noe register; det bruker arkivnøkkelen. For personer er koden fødselsdato og
     * navn («14.03.2016 Måke, Mathias»), og posten har også fødselsdatoen og det oppdiktede fødselsnummeret.
     *
     * @return list<Registerpost>
     */
    public function register(string $arkivdel): array
    {
        $registre = is_array($this->data['registre'] ?? null) ? $this->data['registre'] : [];

        return array_map(
            static fn (array $r): array => [
                'kode' => Felt::tekst($r['kode'] ?? null),
                'tittel' => Felt::tekst($r['tittel'] ?? null),
                'detalj' => Felt::tekst($r['detalj'] ?? null),
                'fodt' => Felt::valgfriTekst($r['fodt'] ?? null),
                'fnr' => Felt::valgfriTekst($r['fnr'] ?? null),
            ],
            self::rader($registre, $arkivdel),
        );
    }

    /**
     * Saksmalene: det studenten velger mellom når en mappe opprettes.
     *
     * @return list<Saksmal>
     */
    public function saksmaler(): array
    {
        return array_map(
            static fn (array $r): array => [
                'kode' => Felt::tekst($r['kode'] ?? null),
                'navn' => Felt::tekst($r['navn'] ?? null),
                'arkivdel' => Felt::tekst($r['arkivdel'] ?? null),
                'tittel' => Felt::tekst($r['tittel'] ?? null),
                'beskrivelse' => Felt::tekst($r['beskrivelse'] ?? null),
                'soketips' => Felt::tekst($r['soketips'] ?? null),
            ],
            self::rader($this->data, 'saksmaler'),
        );
    }

    public function harSaksmal(string $kode): bool
    {
        return in_array($kode, array_column($this->saksmaler(), 'kode'), true);
    }

    /** @return list<Enhet> */
    public function enheter(): array
    {
        return array_map(
            static fn (array $r): array => ['kode' => Felt::tekst($r['kode'] ?? null), 'navn' => Felt::tekst($r['navn'] ?? null)],
            self::rader($this->data, 'enheter'),
        );
    }

    /** Navnet på en enhet, eller koden når enheten ikke finnes. */
    public function enhetsnavn(string $kode): string
    {
        foreach ($this->enheter() as $enhet) {
            if ($enhet['kode'] === $kode) {
                return $enhet['navn'];
            }
        }

        return $kode;
    }

    /** @return list<Ansatt> */
    public function ansatte(): array
    {
        return array_map(
            static fn (array $r): array => [
                'initialer' => Felt::tekst($r['initialer'] ?? null),
                'navn' => Felt::tekst($r['navn'] ?? null),
                'tittel' => Felt::tekst($r['tittel'] ?? null),
                'enhet' => Felt::tekst($r['enhet'] ?? null),
            ],
            self::rader($this->data, 'ansatte'),
        );
    }

    /** @return list<Tilgangskode> */
    public function tilgangskoder(): array
    {
        return array_map(
            static fn (array $r): array => ['kode' => Felt::tekst($r['kode'] ?? null), 'navn' => Felt::tekst($r['navn'] ?? null), 'hjemmel' => Felt::tekst($r['hjemmel'] ?? null)],
            self::rader($this->data, 'tilgangskoder'),
        );
    }

    /**
     * Mappene som ligger i arkivet fra start, med journalpostene og dokumentene.
     *
     * @return list<Startsak>
     */
    public function saker(): array
    {
        if ($this->saker !== null) {
            return $this->saker;
        }
        $ut = [];
        foreach (self::rader($this->data, 'saker') as $r) {
            $poster = [];
            foreach (self::rader($r, 'journalposter') as $nr => $p) {
                $dato = Felt::tekst($p['dokumentdato'] ?? null);
                $dokument = is_array($p['dokument'] ?? null) ? self::dokument($p['dokument'], $dato) : null;
                $poster[] = [
                    'nr' => $nr + 1,
                    'type' => Felt::tekst($p['type'] ?? null),
                    'tittel' => Felt::tekst($p['tittel'] ?? null),
                    'offentlig_tittel' => Felt::valgfriTekst($p['offentlig_tittel'] ?? null),
                    'dokumentdato' => $dato,
                    // Et inngående dokument er registrert den dagen det kom, de andre den dagen de er datert.
                    'journaldato' => $dokument['mottatt'] ?? $dato,
                    'part' => Felt::tekst($p['part'] ?? null),
                    'skjerm_part' => ($p['skjerm_part'] ?? false) === true,
                    'status' => Felt::tekst($p['status'] ?? null, 'J'),
                    'journalfort' => ($p['journalfort'] ?? true) !== false,
                    'tilgangskode' => Felt::valgfriTekst($p['tilgangskode'] ?? null),
                    'dokument' => $dokument,
                ];
            }
            $arkivdel = Felt::tekst($r['arkivdel'] ?? null);
            $ut[] = [
                'nokkel' => Felt::tekst($r['nokkel'] ?? null),
                'tittel' => Felt::tekst($r['tittel'] ?? null),
                'offentlig_tittel' => Felt::valgfriTekst($r['offentlig_tittel'] ?? null),
                'tilgangskode' => Felt::valgfriTekst($r['tilgangskode'] ?? null),
                'arkivdel' => $arkivdel,
                'klasse' => Felt::tekst($r['klasse'] ?? null),
                // I personarkivene er klasseringen fødselsdato og navn, og den skjermes alltid.
                'skjerm_klasse' => is_bool($r['skjerm_klasse'] ?? null) ? $r['skjerm_klasse'] : ($this->arkivdel($arkivdel)['ordning'] ?? '') === self::PERSON,
                'enhet' => Felt::tekst($r['enhet'] ?? null),
                'saksansvarlig' => Felt::tekst($r['saksansvarlig'] ?? null),
                'saksdato' => Felt::tekst($r['saksdato'] ?? null),
                'status' => Felt::tekst($r['status'] ?? null, 'B'),
                'journalposter' => $poster,
            ];
        }

        return $this->saker = $ut;
    }

    /**
     * Dokumentet til en journalpost som fulgte med arkivet. Referansen er «<mappens nøkkel>#<nummer>».
     *
     * @return Dokument|null
     */
    public function startdokument(string $referanse): ?array
    {
        return $this->startpost($referanse)['dokument'] ?? null;
    }

    /**
     * Journalposten som fulgte med arkivet, slik den står i grunndataene. Referansen er «<mappens nøkkel>#<nummer>».
     *
     * @return Startpost|null
     */
    public function startpost(string $referanse): ?array
    {
        [$nokkel, $nr] = explode('#', $referanse, 2) + [1 => ''];
        foreach ($this->saker() as $sak) {
            if ($sak['nokkel'] !== $nokkel) {
                continue;
            }
            foreach ($sak['journalposter'] as $post) {
                if ((string) $post['nr'] === $nr) {
                    return $post;
                }
            }
        }

        return null;
    }

    public function harArkivdel(string $kode): bool
    {
        return $this->arkivdel($kode) !== null;
    }

    /**
     * Om en mappe i arkivdelen kan ha klassen: i sakarkivet en klasse på laveste nivå i arkivnøkkelen (ikke en
     * hovedgruppe som er bygd ut, og ikke en henvisning til en annen arkivdel), ellers en post i arkivdelens
     * register.
     */
    public function harKlasse(string $arkivdel, string $kode): bool
    {
        $del = $this->arkivdel($arkivdel);
        if ($del === null) {
            return false;
        }
        if ($del['ordning'] !== self::EMNE) {
            return in_array($kode, array_column($this->register($arkivdel), 'kode'), true);
        }
        $foreldre = array_column($this->klasser(), 'forelder');
        foreach ($this->klasser() as $k) {
            if ($k['kode'] === $kode) {
                return $k['forelder'] !== null && $k['henvisning'] === null && !in_array($kode, $foreldre, true);
            }
        }

        return false;
    }

    public function harEnhet(string $kode): bool
    {
        return in_array($kode, array_column($this->enheter(), 'kode'), true);
    }

    public function harTilgangskode(string $kode): bool
    {
        return in_array($kode, array_column($this->tilgangskoder(), 'kode'), true);
    }

    /** @return Ansatt|null */
    public function ansatt(string $initialer): ?array
    {
        foreach ($this->ansatte() as $a) {
            if ($a['initialer'] === $initialer) {
                return $a;
            }
        }

        return null;
    }

    /** Den første ansatte i enheten: saksansvarlig når studenten ikke har valgt noen. */
    public function forsteIEnhet(string $enhet): string
    {
        foreach ($this->ansatte() as $a) {
            if ($a['enhet'] === $enhet) {
                return $a['initialer'];
            }
        }

        return $this->ansatte()[0]['initialer'] ?? '';
    }

    /**
     * Klasseringen til visning: «212 Tilsetting» i sakarkivet, «9/112 Fyrveien 8» i eiendomsarkivet, «KST
     * Kommunestyret» i møtearkivet, og koden alene i personarkivene (den er fødselsdato og navn) og når den er ukjent.
     */
    public function klassetekst(string $arkivdel, string $kode): string
    {
        $del = $this->arkivdel($arkivdel);
        if ($del !== null && $del['ordning'] === self::PERSON) {
            return $kode;
        }
        $poster = $del !== null && $del['ordning'] !== self::EMNE ? $this->register($arkivdel) : $this->klasser();
        foreach ($poster as $p) {
            if ($p['kode'] === $kode) {
                return $kode . ' ' . $p['tittel'];
            }
        }

        return $kode;
    }

    public function arkivdeltekst(string $kode): string
    {
        return $this->arkivdel($kode)['tittel'] ?? $kode;
    }

    /** «Offl. § 25 første ledd (Tilsettingssaker)», eller tom tekst for ingen tilgangskode. */
    public function tilgangstekst(?string $kode): string
    {
        foreach ($this->tilgangskoder() as $t) {
            if ($t['kode'] === $kode) {
                return $t['hjemmel'] . ' (' . $t['navn'] . ')';
            }
        }

        return '';
    }
}
