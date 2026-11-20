<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Enhet;

use ArkIvar\Arkiv\Dato;
use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Kodelister;
use ArkIvar\Arkiv\Oppgavesett;
use ArkIvar\Arkiv\Vurdering;
use Bivrost\Kjerne\Tester\Stotte\FastKlokke;
use PHPUnit\Framework\TestCase;

/**
 * Vurderingen av en registrering mot fasiten, kriterium for kriterium, med oppgavene som følger med Ark-Ivar:
 * mappen, skjermingen begge veier, navnet på avsenderen og forklaringen om skjerming.
 *
 * @phpstan-import-type Oppgave from Oppgavesett
 * @phpstan-import-type Svar from Vurdering
 * @phpstan-import-type Kriterium from Vurdering
 */
final class VurderingTest extends TestCase
{
    private Grunndata $grunndata;
    private Oppgavesett $oppgaver;

    protected function setUp(): void
    {
        $this->grunndata = new Grunndata();
        $this->oppgaver = new Oppgavesett();
    }

    /** @return Oppgave */
    private function oppgave(string $id): array
    {
        return $this->oppgaver->hent($id) ?? self::fail("Oppgaven $id finnes ikke.");
    }

    /**
     * Svaret som følger fasiten for et dokument som skal i arkivet.
     *
     * @param Oppgave $oppgave
     * @return Svar
     */
    private function riktig(array $oppgave): array
    {
        $fasit = $oppgave['fasit'];
        $sak = $fasit['sak'] ?? self::fail($oppgave['id'] . ' skal ikke i arkivet.');
        $ny = $sak['type'] === 'ny';
        $person = ($this->grunndata->arkivdel($sak['arkivdel'])['ordning'] ?? '') === Grunndata::PERSON;

        return [
            'handling' => $fasit['handling'],
            'sak_ny' => $ny,
            'sak_nokkel' => $sak['nokkel'],
            'sak_tekst' => '2026/1 Mappen',
            'sak_offentlig' => $sak['offentlig_tittel'] ?? (string) $sak['tittel'],
            'sak_skjermet' => $sak['offentlig_tittel'] !== null,
            'sak_alene' => true,
            'arkivdel' => $sak['arkivdel'],
            'klasse' => $sak['klasse'][0],
            'skjerm_klasse' => $ny && $person,
            'type' => $fasit['type'] ?? 'I',
            'tittel' => (string) $fasit['tittel'],
            'offentlig_tittel' => $fasit['offentlig_tittel'],
            'tilgangskode' => $fasit['tilgangskode'][0] ?? null,
            'skjerm_tittel' => $fasit['skjerm_tittel'] === true,
            'skjerm_part' => $fasit['skjerm_part'] === true,
            'dokumentdato' => $oppgave['dokumentdato'],
            'part_navn' => $oppgave['avsender']['navn'],
        ];
    }

    /**
     * Vurderingen av svaret som følger fasiten, etter at $endre har gjort om på det. Etter kriterium.
     *
     * @param (callable(Svar): Svar)|null $endre
     * @return array<string, Kriterium>
     */
    private function vurder(string $oppgaveId, ?callable $endre = null): array
    {
        $oppgave = $this->oppgave($oppgaveId);
        $svar = $this->riktig($oppgave);
        $ut = [];
        foreach (Vurdering::vurder($oppgave, $endre !== null ? $endre($svar) : $svar, $this->grunndata) as $kriterium) {
            $ut[$kriterium['id']] = $kriterium;
        }

        return $ut;
    }

    public function testFasitenGirRiktigPaaAlleKriteriene(): void
    {
        $sett = 0;
        foreach ($this->oppgaver->alle() as $oppgave) {
            if ($oppgave['fasit']['sak'] === null) {
                continue;
            }
            $sett++;
            $avvik = array_keys(array_filter($this->vurder($oppgave['id']), static fn (array $k): bool => !$k['ok']));
            self::assertSame([], $avvik, $oppgave['id']);
        }
        self::assertGreaterThan(15, $sett);
    }

    public function testFeilBehandlingVurdererIkkeResten(): void
    {
        $vurdering = $this->vurder('horing-verneplan', static function (array $s): array {
            $s['handling'] = Kodelister::ARKIVER;

            return $s;
        });
        self::assertSame(['handling'], array_keys($vurdering));
        self::assertFalse($vurdering['handling']['ok']);
        self::assertNotSame('', $vurdering['handling']['forklaring']);
    }

    public function testEnNySakSkalHaSinEgenMappe(): void
    {
        self::assertSame('ny', $this->vurder('horing-verneplan')['sak']['ditt']);
        // Mappen deles med et dokument fra en annen sak.
        $delt = $this->vurder('horing-verneplan', static function (array $s): array {
            $s['sak_alene'] = false;

            return $s;
        });
        self::assertFalse($delt['sak']['ok']);
        self::assertSame('2026/1 Mappen', $delt['sak']['ditt']);
        // En mappe som fulgte med arkivet, er ikke en ny sak.
        $gammel = $this->vurder('horing-verneplan', static function (array $s): array {
            $s['sak_ny'] = false;
            $s['sak_nokkel'] = 'tilsyn-arkivhold-2026';

            return $s;
        });
        self::assertFalse($gammel['sak']['ok']);
        self::assertArrayNotHasKey('skjerm_klasse', $gammel, 'Klasseringen på en mappe som fulgte med, er ikke studentens valg.');
        // Fasiten er en mappe som finnes: en ny mappe er feil, også når den er alene.
        $ny = $this->vurder('klage-fyrveien', static function (array $s): array {
            $s['sak_ny'] = true;
            $s['sak_nokkel'] = null;

            return $s;
        });
        self::assertFalse($ny['sak']['ok']);
        self::assertSame(['ny', 'eksisterende'], [$ny['sak']['ditt'], $ny['sak']['riktig']]);
    }

    public function testSkjermingenPaaMappenVurderesBeggeVeier(): void
    {
        // Sakarkivet: verken tittelen eller klasseringen skal skjermes.
        $apen = $this->vurder('horing-verneplan');
        self::assertTrue($apen['skjerm_klasse']['ok']);
        self::assertTrue($apen['skjerm_sakstittel']['ok']);
        $skjermet = $this->vurder('horing-verneplan', static function (array $s): array {
            $s['skjerm_klasse'] = true;
            $s['sak_skjermet'] = true;
            $s['sak_offentlig'] = 'Høring – *****';

            return $s;
        });
        self::assertFalse($skjermet['skjerm_klasse']['ok']);
        self::assertSame(['ja', 'nei'], [$skjermet['skjerm_klasse']['ditt'], $skjermet['skjerm_klasse']['riktig']]);
        self::assertFalse($skjermet['skjerm_sakstittel']['ok']);

        // Personarkivene: klasseringen er fødselsdato og navn og skal skjermes. Tittelen har en offentlig tittel i
        // fasiten, så skjermingen av den vurderes på navnet, ikke på avkrysningen.
        $elev = $this->vurder('innmelding-lerke', static function (array $s): array {
            $s['skjerm_klasse'] = false;

            return $s;
        });
        self::assertFalse($elev['skjerm_klasse']['ok']);
        self::assertSame('ja', $elev['skjerm_klasse']['riktig']);
        self::assertArrayNotHasKey('skjerm_sakstittel', $elev);
        $navn = $this->vurder('innmelding-lerke', static function (array $s): array {
            $s['sak_skjermet'] = false;
            $s['sak_offentlig'] = 'Elevmappe – Lerke, Leah';

            return $s;
        });
        self::assertFalse($navn['offentlig_sakstittel']['ok']);

        // Fasiten er en mappe som finnes, men studenten har opprettet en ny: klasseringen på den vurderes etter
        // arkivdelen studenten valgte.
        $nyElev = $this->vurder('tilrettelegging-elev', static function (array $s): array {
            $s['sak_ny'] = true;
            $s['sak_nokkel'] = null;
            $s['sak_offentlig'] = 'Elevmappe – *****';

            return $s;
        });
        self::assertFalse($nyElev['sak']['ok']);
        self::assertFalse($nyElev['skjerm_klasse']['ok'], 'Fødselsdato og navn står åpent.');
        $nySak = $this->vurder('klage-fyrveien', static function (array $s): array {
            $s['sak_ny'] = true;
            $s['sak_nokkel'] = null;
            $s['arkivdel'] = 'SAK';
            $s['klasse'] = '512';

            return $s;
        });
        self::assertTrue($nySak['skjerm_klasse']['ok']);
        self::assertArrayNotHasKey('skjerm_sakstittel', $nySak);
    }

    public function testTittelenSkjermesBareNaarDenRoperNoe(): void
    {
        // Varselet: tittelen kan stå åpent så lenge den ikke nevner navn.
        self::assertTrue($this->vurder('varsel-arbeidsmiljo')['skjerm_tittel']['ok']);
        $utenGrunn = $this->vurder('varsel-arbeidsmiljo', static function (array $s): array {
            $s['skjerm_tittel'] = true;
            $s['offentlig_tittel'] = 'Varsel';

            return $s;
        });
        self::assertFalse($utenGrunn['skjerm_tittel']['ok']);
        self::assertTrue($utenGrunn['offentlig_tittel']['ok']);
        $medNavn = $this->vurder('varsel-arbeidsmiljo', static function (array $s): array {
            $s['tittel'] = 'Varsel om Geir Gaupes oppførsel';
            $s['skjerm_tittel'] = true;
            $s['offentlig_tittel'] = 'Varsel om kritikkverdige forhold';

            return $s;
        });
        self::assertTrue($medNavn['skjerm_tittel']['ok'], 'Studenten skrev navnet i tittelen, og da må den skjermes.');
        self::assertTrue($medNavn['offentlig_tittel']['ok']);
        $roper = $this->vurder('varsel-arbeidsmiljo', static function (array $s): array {
            $s['tittel'] = 'Varsel om Geir Gaupes oppførsel';

            return $s;
        });
        self::assertFalse($roper['offentlig_tittel']['ok'], 'Navnet i genitiv røper også hvem det gjelder.');
        self::assertTrue($roper['skjerm_tittel']['ok'], 'Avviket er navnet i tittelen, ikke en skjerming uten grunn.');
        // Deler av et ord er ikke navnet.
        $ordet = $this->vurder('varsel-arbeidsmiljo', static function (array $s): array {
            $s['tittel'] = 'Varsel om gaupejakt og hegrekoloni';

            return $s;
        });
        self::assertTrue($ordet['offentlig_tittel']['ok']);
    }

    public function testAvsenderenSammenliknesOrdForOrd(): void
    {
        $tilfeller = [
            'arbeidsavtale-due' => ['Dina Due' => true, 'Due, Dina' => true, 'dina due' => true, 'Dina' => false, 'Dina Duestad' => false, '' => false],
            'tilrettelegging-elev' => ['Marit og Rune Måke' => true, 'Rune og Marit Måke' => true, 'Marit Måke' => true, 'Måke' => false, 'Foresatte' => false],
            'nabomerknad-havnegata' => ['Sirevåg Rør og Varme AS' => true, 'Sirevåg Rør og Varme' => true, 'Sirevåg' => false, 'Sirevåg kommune' => false, 'Rørlegger' => false],
            'tilbud-renhold' => ['Jærrent AS' => true, 'Jærrent' => true, 'AS' => false, 'Jær' => false],
            // Fasiten godtar også kortformen av avsenderen.
            'horing-verneplan' => ['Statsforvalteren i Rogaland' => true, 'Statsforvalteren' => true, 'Rogaland fylkeskommune' => false],
        ];
        foreach ($tilfeller as $oppgaveId => $navn) {
            foreach ($navn as $skrevet => $riktig) {
                $vurdering = $this->vurder($oppgaveId, static function (array $s) use ($skrevet): array {
                    $s['part_navn'] = $skrevet;

                    return $s;
                });
                self::assertSame($riktig, $vurdering['part']['ok'], "$oppgaveId: «{$skrevet}»");
            }
        }
    }

    public function testForklaringenOmSkjermingStaarEnGang(): void
    {
        $skjerming = ['skjerm_klasse', 'skjerm_sakstittel', 'offentlig_sakstittel', 'offentlig_tittel', 'skjerm_tittel', 'skjerm_part'];
        // Uten avvik står den ved det første kriteriet om skjerming.
        $riktig = array_intersect_key($this->vurder('innmelding-lerke'), array_flip($skjerming));
        self::assertGreaterThan(2, count($riktig));
        self::assertCount(1, array_filter($riktig, static fn (array $k): bool => $k['forklaring'] !== ''));
        self::assertNotSame('', array_values($riktig)[0]['forklaring']);
        // Med et avvik står den ved avviket.
        $avvik = $this->vurder('innmelding-lerke', static function (array $s): array {
            $s['skjerm_part'] = false;

            return $s;
        });
        self::assertFalse($avvik['skjerm_part']['ok']);
        self::assertNotSame('', $avvik['skjerm_part']['forklaring']);
        self::assertSame('', $avvik['skjerm_klasse']['forklaring']);
    }

    public function testDatoenFolgerNorskTid(): void
    {
        $klokke = new FastKlokke();
        $klokke->sett('2026-11-20 22:59:00');
        self::assertSame('2026-11-20', Dato::idag($klokke));
        // Klokka i Kjerne går i UTC. Etter midnatt i Norge er det en ny dag, også om vinteren (UTC+1) …
        $klokke->sett('2026-11-20 23:00:00');
        self::assertSame('2026-11-21', Dato::idag($klokke));
        // … og om sommeren (UTC+2).
        $klokke->sett('2026-06-30 22:00:00');
        self::assertSame('2026-07-01', Dato::idag($klokke));
    }
}
