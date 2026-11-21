<?php

declare(strict_types=1);

namespace ArkIvar\Tester\EndeTilEnde;

use ArkIvar\Arkiv\Grunndata;
use ArkIvar\Arkiv\Kodelister;
use ArkIvar\Arkiv\Oppgavesett;
use ArkIvar\Arkiv\Ovingsarkiv;
use ArkIvar\Tester\Stotte\AppTestCase;
use ArkIvar\Tester\Stotte\Nettleser;
use Bivrost\Kjerne\Database\Felt;

/**
 * Arbeidsflyten etter registreringen: avskrivning og restanser, svar på et inngående dokument, retting og flytting
 * av journalposter, sjekklisten før en mappe avsluttes, og stegene en journalpost og en mappe går gjennom.
 */
final class ArbeidsflytTest extends AppTestCase
{
    private Nettleser $kari;
    /** ID-en til Karis arkiv: spørringene rett mot databasen gjelder det, også når en annen student har et arkiv. */
    private string $arkiv = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Eksempeldokumentene er datert i november 2026; dokumentdatoen kan ikke ligge frem i tid.
        $this->klokke->sett('2026-11-20 09:00:00');
        $this->godkjennDomene();
        $this->kari = $this->student('kari@hogskolen.example');
        // Arkivet lages og fylles første gang det åpnes.
        [$svar] = $this->kari->kall('GET', '/api/arkiv');
        self::assertSame(200, $svar->status, $svar->kropp);
        $this->arkiv = Felt::tekst($this->db->verdi('SELECT id FROM arkivar_arkiv'));
    }

    /**
     * Mappen med denne nøkkelen i Karis arkiv, med journalpostene.
     *
     * @return array{sak: array<string, mixed>, journalposter: list<array<string, mixed>>}
     */
    private function mappe(string $nokkel): array
    {
        $id = Felt::tekst($this->db->verdi('SELECT id FROM arkivar_sak WHERE arkiv_id = ? AND nokkel = ?', [$this->arkiv, $nokkel]));

        return $this->mappeMedId($id);
    }

    /** @return array{sak: array<string, mixed>, journalposter: list<array<string, mixed>>} */
    private function mappeMedId(string $id): array
    {
        [$svar, $j] = $this->kari->kall('GET', "/api/arkiv/saker/$id");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['sak']);
        $sak = [];
        foreach ($j['sak'] as $nokkel => $verdi) {
            $sak[(string) $nokkel] = $verdi;
        }

        return ['sak' => $sak, 'journalposter' => self::rader($j['journalposter'])];
    }

    /** @return array<string, mixed> */
    private function post(string $id): array
    {
        [$svar, $j] = $this->kari->kall('GET', "/api/arkiv/journalposter/$id");
        self::assertSame(200, $svar->status, $svar->kropp);

        return self::rader([$j['journalpost']])[0];
    }

    /** @return array{utgaende: int, restanser: int} antall utgående dokumenter til journalføring, og restansene */
    private function tall(): array
    {
        [$svar, $tall] = $this->kari->kall('GET', '/api/arkiv/kontroll');
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($tall['journalforing']);

        return ['utgaende' => Felt::tall($tall['journalforing']['utgaende'] ?? null), 'restanser' => Felt::tall($tall['restanser'])];
    }

    /**
     * Restansene i arkivet.
     *
     * @return list<array<string, mixed>>
     */
    private function restanser(): array
    {
        [$svar, $j] = $this->kari->kall('GET', '/api/arkiv/kontroll/restanser');
        self::assertSame(200, $svar->status, $svar->kropp);

        return self::rader($j['journalposter']);
    }

    /**
     * Et svarbrev fra skriveflaten.
     *
     * @return array<string, mixed>
     */
    private static function brev(string $mal = 'utgaende-brev'): array
    {
        return [
            'mal' => $mal, 'type' => 'U', 'tittel' => 'Svar på krav til lokale lønnsforhandlinger 2026', 'dokumentdato' => '2026-11-20', 'part_navn' => 'Utdanningsforbundet Sirevåg',
            'tilgangskode' => '', 'emne' => 'Svar på krav til lokale lønnsforhandlinger 2026', 'mottaker' => 'Utdanningsforbundet Sirevåg', 'tekst' => ['Vi viser til kravet.'], 'vedlegg' => [],
        ];
    }

    public function testArkivetHarAvskrivningerOgRestanserFraStart(): void
    {
        // Tallet er fast: de ti nyeste journalførte inngående dokumentene uten svar i åpne mapper, bortsett fra
        // høringene i samlemappen, som er tatt til etterretning.
        $restanser = $this->restanser();
        self::assertCount(Ovingsarkiv::RESTANSER_FRA_START, $restanser);
        self::assertSame(10, $this->tall()['restanser']);
        foreach ($restanser as $post) {
            self::assertSame(['I', 'J', true, true, null], [$post['type'], $post['status'], $post['journalfort'], $post['restanse'], $post['avskrivning']]);
            self::assertSame('avskriv', $post['neste'], 'Neste steg for en restanse er å avskrive den.');
            self::assertIsArray($post['handlinger']);
            self::assertContains('svar', $post['handlinger']);
            self::assertNotSame('', $post['saksnummer'] ?? '');
        }
        // Eldst først, og dagene regnes fra journaldatoen til i dag (20.11.2026).
        $datoer = array_column($restanser, 'journaldato');
        $sortert = $datoer;
        sort($sortert);
        self::assertSame($sortert, $datoer);
        self::assertSame('2026-09-21', $datoer[0]);
        self::assertSame(60, $restanser[0]['restanse_dager']);
        self::assertSame('2026-11-02', $datoer[9]);
        self::assertSame(18, $restanser[9]['restanse_dager']);

        // Resten av de inngående dokumentene er avskrevet: besvart med et senere utgående dokument i mappen (BU),
        // saken avsluttet (SA), eller tatt til etterretning (TE, de eldre uten svar). Bare de som står til
        // journalføring uten svar, er uten avskrivning.
        $mater = [];
        foreach ($this->db->alle('SELECT a.mate, COUNT(*) AS antall FROM arkivar_avskrivning a GROUP BY a.mate') as $rad) {
            $mater[Felt::tekst($rad['mate'])] = Felt::tall($rad['antall']);
        }
        ksort($mater);
        self::assertSame(['BU' => 44, 'SA' => 7, 'TE' => 15], $mater);
        $uten = $this->db->alle("SELECT j.status FROM arkivar_journalpost j WHERE j.type = 'I' AND NOT EXISTS (SELECT 1 FROM arkivar_avskrivning a WHERE a.journalpost_id = j.id) ORDER BY j.status");
        self::assertSame(array_merge(array_fill(0, 10, 'J'), ['M', 'S', 'S']), array_column($uten, 'status'));
        self::assertSame(0, Felt::tall($this->db->verdi(
            "SELECT COUNT(*) FROM arkivar_avskrivning a JOIN arkivar_journalpost j ON j.id = a.journalpost_id JOIN arkivar_sak s ON s.id = j.sak_id WHERE a.mate = 'SA' AND s.status <> 'A'",
        )), 'SA står bare i avsluttede mapper.');
        self::assertSame(0, Felt::tall($this->db->verdi(
            "SELECT COUNT(*) FROM arkivar_avskrivning a JOIN arkivar_journalpost i ON i.id = a.journalpost_id JOIN arkivar_journalpost u ON u.id = a.avskrevet_av
              WHERE u.type <> 'U' OR u.sak_id <> i.sak_id OR u.postnr <= i.postnr OR i.type <> 'I'",
        )), 'Et inngående dokument er besvart med et senere utgående dokument i samme mappe.');
        // Et foreløpig svar avskriver ikke, og svaret går til den som sendte dokumentet (ikke et brev til en annen part).
        $bu = $this->db->alle(
            "SELECT i.part_navn AS avsender, u.part_navn AS mottaker, u.tittel FROM arkivar_avskrivning a
               JOIN arkivar_journalpost i ON i.id = a.journalpost_id JOIN arkivar_journalpost u ON u.id = a.avskrevet_av WHERE a.mate = 'BU'",
        );
        self::assertCount(44, $bu);
        foreach ($bu as $rad) {
            self::assertStringStartsNotWith('Foreløpig svar', Felt::tekst($rad['tittel']));
            self::assertTrue(\ArkIvar\Arkiv\Vurdering::sammePart(Felt::tekst($rad['avsender']), Felt::tekst($rad['mottaker'])), Felt::tekst($rad['avsender']) . ' / ' . Felt::tekst($rad['mottaker']));
        }
        // Søknaden i Fyrveien 8 er besvart med tillatelsen (dok. 4), ikke med det foreløpige svaret (dok. 2), og
        // søknaden i Havnegata 12 har bare fått foreløpig svar og er ikke avskrevet.
        $fyrveien = $this->mappe('byggesak-fyrveien-8')['journalposter'];
        $etterNr = array_column($fyrveien, null, 'postnr');
        self::assertStringStartsWith('Foreløpig svar', Felt::tekst($etterNr[2]['tittel']));
        self::assertIsArray($etterNr[1]['avskrivning']);
        self::assertIsArray($etterNr[1]['avskrivning']['av']);
        self::assertSame(['BU', 4], [$etterNr[1]['avskrivning']['mate'], $etterNr[1]['avskrivning']['av']['postnr']]);
        self::assertSame([], $etterNr[2]['svar_paa']);
        $havnegata = array_column($this->mappe('bruksendring-havnegata-12')['journalposter'], null, 'postnr');
        self::assertSame([null, null], [$havnegata[1]['avskrivning'], $havnegata[3]['avskrivning']]);
        self::assertSame([true, false], [$havnegata[1]['restanse'], $havnegata[3]['restanse']], 'Dok. 3 står til journalføring og er ikke en restanse ennå.');
        // Høringene kommunen ikke uttaler seg til, er tatt til etterretning den dagen de ble journalført.
        foreach ($this->mappe('horinger-2026')['journalposter'] as $post) {
            if ($post['type'] !== 'I') {
                continue;
            }
            self::assertIsArray($post['avskrivning']);
            self::assertSame(['TE', $post['journaldato'], false], [$post['avskrivning']['mate'], $post['avskrivning']['dato'], $post['restanse']]);
        }

        // Koblingen vises begge veier: «Avskrevet av 2» på det inngående og «Svar på 1» på det utgående.
        $rad = $this->db->en("SELECT a.journalpost_id, a.avskrevet_av FROM arkivar_avskrivning a WHERE a.mate = 'BU' LIMIT 1");
        self::assertNotNull($rad);
        $inn = $this->post(Felt::tekst($rad['journalpost_id']));
        $ut = $this->post(Felt::tekst($rad['avskrevet_av']));
        self::assertIsArray($inn['avskrivning']);
        self::assertSame('BU', $inn['avskrivning']['mate']);
        self::assertIsArray($inn['avskrivning']['av']);
        self::assertSame([$ut['id'], $ut['postnr'], $ut['sak_id']], [$inn['avskrivning']['av']['id'], $inn['avskrivning']['av']['postnr'], $inn['avskrivning']['av']['sak_id']]);
        self::assertFalse($inn['restanse']);
        self::assertContains([$inn['id'], true], array_map(static fn (array $s): array => [$s['id'], $s['avskriver']], self::rader($ut['svar_paa'])));
        // Den offentlige journalen har ikke avskrivningen.
        [, $j] = $this->kari->kall('GET', '/api/arkiv/journal?offentlig=1');
        foreach (self::rader($j['journalposter']) as $post) {
            self::assertSame([null, [], false, []], [$post['avskrivning'], $post['svar_paa'], $post['restanse'], $post['handlinger']]);
        }
    }

    public function testAvskrivningUtenSvarMedMerknadOgAngring(): void
    {
        $post = $this->restanser()[0];
        $id = Felt::tekst($post['id']);
        // BU settes av svaret og SA når saksmappen avsluttes: ingen av dem kan velges. Merknaden har en grense.
        foreach ([['mate' => 'BU'], ['mate' => 'SA'], ['mate' => 'XX'], [], ['mate' => 'TE', 'merknad' => str_repeat('a', 2001)]] as $feil) {
            [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/avskriv", $feil);
            self::assertSame(422, $svar->status, json_encode($feil, JSON_THROW_ON_ERROR) . ' ' . $svar->kropp);
        }
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        [$svar] = $ola->kall('POST', "/api/arkiv/journalposter/$id/avskriv", ['mate' => 'TE']);
        self::assertSame(404, $svar->status);

        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/avskriv", ['mate' => 'TE', 'merknad' => ' Tatt til etterretning etter samtale med saksansvarlig. ']);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(['mate' => 'TE', 'dato' => '2026-11-20', 'av' => null], $j['journalpost']['avskrivning']);
        self::assertSame([false, null], [$j['journalpost']['restanse'], $j['journalpost']['neste']]);
        self::assertIsArray($j['journalpost']['handlinger']);
        self::assertContains('angre_avskrivning', $j['journalpost']['handlinger']);
        self::assertNotContains('avskriv', $j['journalpost']['handlinger']);
        self::assertNotContains('svar', $j['journalpost']['handlinger']);
        self::assertSame(9, $this->tall()['restanser']);
        // Merknaden står på journalposten med hvem som skrev den, og når.
        [, $j] = $this->kari->kall('GET', "/api/arkiv/journalposter/$id");
        $merknader = self::rader($j['merknader']);
        self::assertCount(1, $merknader);
        self::assertSame(['Tatt til etterretning etter samtale med saksansvarlig.', 'Kari Student'], [$merknader[0]['tekst'], $merknader[0]['av']]);
        self::assertStringStartsWith('2026-11-20T09:', Felt::tekst($merknader[0]['tidspunkt']));

        // Et avskrevet dokument kan ikke avskrives på nytt, og det kan ikke besvares.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/avskriv", ['mate' => 'TLF']);
        self::assertSame([409, 'alt_avskrevet'], [$svar->status, $j['kode']]);
        [$svar, $j] = $this->kari->kall('GET', "/api/arkiv/journalposter/$id/svar");
        self::assertSame([409, 'kan_ikke_besvares'], [$svar->status, $j['kode']]);
        // Bare inngående dokumenter kan avskrives.
        $utgaende = Felt::tekst($this->db->verdi("SELECT j.id FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id WHERE j.arkiv_id = ? AND j.type = 'U' AND j.status = 'J' AND s.status = 'B' LIMIT 1", [$this->arkiv]));
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$utgaende/avskriv", ['mate' => 'TE']);
        self::assertSame([409, 'kan_ikke_avskrives'], [$svar->status, $j['kode']]);

        // Angring: dokumentet er igjen en restanse. Merknaden står.
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$id/avskrivning");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame([null, true], [$j['journalpost']['avskrivning'], $j['journalpost']['restanse']]);
        self::assertSame(10, $this->tall()['restanser']);
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$id/avskrivning");
        self::assertSame([409, 'ikke_avskrevet'], [$svar->status, $j['kode']]);
        self::assertSame(1, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_merknad')));

        // I en avsluttet mappe er avskrivningen låst (SA kan ikke angres).
        $sa = Felt::tekst($this->db->verdi("SELECT journalpost_id FROM arkivar_avskrivning WHERE arkiv_id = ? AND mate = 'SA' LIMIT 1", [$this->arkiv]));
        self::assertNotContains('angre_avskrivning', (array) $this->post($sa)['handlinger']);
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$sa/avskrivning");
        self::assertSame([409, 'mappe_avsluttet'], [$svar->status, $j['kode']]);

        // Nullstillingen tar med seg avskrivningene og merknadene, og arkivet har igjen ti restanser.
        $this->kari->kall('POST', "/api/arkiv/journalposter/$id/avskriv", ['mate' => 'TO', 'merknad' => 'Til orientering.']);
        [$svar] = $this->kari->kall('POST', '/api/arkiv/nullstill', []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(0, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_merknad')));
        self::assertSame(66, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_avskrivning WHERE arkiv_id = ?', [$this->arkiv])));
        self::assertSame(10, $this->tall()['restanser']);
    }

    public function testSvarAvskriverDetInngaaendeNaarDetErEkspedert(): void
    {
        $mappe = $this->mappe('lonnsforhandlinger-2026');
        $sakId = Felt::tekst($mappe['sak']['id']);
        $restanser = array_values(array_filter($mappe['journalposter'], static fn (array $p): bool => $p['restanse'] === true));
        self::assertCount(2, $restanser);
        [$krav, $annet] = $restanser;
        $kravId = Felt::tekst($krav['id']);

        // Forslaget til svaret: tittelen «Svar på …» og mottakeren fra avsenderen.
        [$svar, $j] = $this->kari->kall('GET', "/api/arkiv/journalposter/$kravId/svar");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['forslag']);
        self::assertSame('Svar på ' . mb_strtolower(mb_substr(Felt::tekst($krav['tittel']), 0, 1)) . mb_substr(Felt::tekst($krav['tittel']), 1), $j['forslag']['tittel']);
        self::assertSame($krav['part_navn'], $j['forslag']['mottaker']);

        // Svaret må være utgående og besvare et inngående dokument i samme mappe.
        $annenMappe = Felt::tekst($this->mappe('horinger-2026')['sak']['id']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$annenMappe/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertSame([422, 'ugyldig_felt'], [$svar->status, $j['kode']]);
        $utgaende = Felt::tekst(array_values(array_filter($mappe['journalposter'], static fn (array $p): bool => $p['type'] !== 'I'))[0]['id']);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev() + ['svar_paa' => $utgaende]);
        self::assertSame(422, $svar->status, $svar->kropp);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", ['mal' => 'internt-notat', 'type' => 'X'] + self::brev() + ['svar_paa' => $kravId]);
        self::assertSame(422, $svar->status, $svar->kropp);

        // Et svar som slettes før det er ekspedert, etterlater ingenting: koblingen forsvinner.
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertIsArray($j['journalpost']);
        self::assertSame(1, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_svar')));
        [$svar] = $this->kari->kall('DELETE', '/api/arkiv/journalposter/' . Felt::tekst($j['journalpost']['id']));
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertSame(0, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_svar')));

        // Mens svaret skrives (R), er det inngående fortsatt en restanse, men svaret viser hva det besvarer.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        $svarId = Felt::tekst($j['journalpost']['id']);
        self::assertSame('ferdigstill', $j['journalpost']['neste']);
        self::assertContains('slett', (array) $j['journalpost']['handlinger']);
        self::assertSame([[$kravId, false]], array_map(static fn (array $s): array => [$s['id'], $s['avskriver']], self::rader($j['journalpost']['svar_paa'])));
        self::assertTrue($this->post($kravId)['restanse']);

        // Ferdigstilt og ekspedert: det inngående er avskrevet med BU, og de to viser til hverandre.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/ferdigstill", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame('E', $j['journalpost']['status']);
        self::assertSame([[$kravId, true]], array_map(static fn (array $s): array => [$s['id'], $s['avskriver']], self::rader($j['journalpost']['svar_paa'])));
        $besvart = $this->post($kravId);
        self::assertIsArray($besvart['avskrivning']);
        self::assertSame(['BU', '2026-11-20'], [$besvart['avskrivning']['mate'], $besvart['avskrivning']['dato']]);
        self::assertIsArray($besvart['avskrivning']['av']);
        self::assertSame([$svarId, $j['journalpost']['postnr']], [$besvart['avskrivning']['av']['id'], $besvart['avskrivning']['av']['postnr']]);
        self::assertSame(9, $this->tall()['restanser']);

        // Et ekspedert svar kan ikke slettes, heller ikke når det er journalført: da ville avskrivningen forsvunnet
        // og journalen fått et hull. BU følger svaret og kan ikke angres for seg.
        self::assertNotContains('slett', (array) $j['journalpost']['handlinger']);
        self::assertFalse($j['journalpost']['kan_slettes']);
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$svarId");
        self::assertSame([409, 'kan_ikke_slettes'], [$svar->status, $j['kode']]);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/journalfor", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertNotContains('slett', (array) $this->post($svarId)['handlinger']);
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$svarId");
        self::assertSame([409, 'kan_ikke_slettes'], [$svar->status, $j['kode']]);
        self::assertNotContains('angre_avskrivning', (array) $besvart['handlinger']);
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$kravId/avskrivning");
        self::assertSame([409, 'avskrivning_kan_ikke_angres'], [$svar->status, $j['kode']]);
        self::assertSame('BU', $this->db->verdi('SELECT mate FROM arkivar_avskrivning WHERE journalpost_id = ?', [$kravId]));
        self::assertSame(1, Felt::tall($this->db->verdi('SELECT COUNT(*) FROM arkivar_svar')));
        // Heller ikke BU-avskrivningene som fulgte med arkivet, kan angres.
        $fraStart = Felt::tekst($this->db->verdi(
            "SELECT a.journalpost_id FROM arkivar_avskrivning a JOIN arkivar_journalpost j ON j.id = a.journalpost_id JOIN arkivar_sak s ON s.id = j.sak_id
              WHERE a.mate = 'BU' AND a.journalpost_id <> ? AND s.status = 'B' AND j.status = 'J' LIMIT 1",
            [$kravId],
        ));
        self::assertNotContains('angre_avskrivning', (array) $this->post($fraStart)['handlinger']);
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$fraStart/avskrivning");
        self::assertSame([409, 'avskrivning_kan_ikke_angres'], [$svar->status, $j['kode']]);

        // Et foreløpig svar avskriver ikke: restansen står.
        $annetId = Felt::tekst($annet['id']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev('forelopig-svar') + ['svar_paa' => $annetId]);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        $forelopig = Felt::tekst($j['journalpost']['id']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$forelopig/ferdigstill", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame([[$annetId, false]], array_map(static fn (array $s): array => [$s['id'], $s['avskriver']], self::rader($j['journalpost']['svar_paa'])));
        self::assertSame([null, true], [$this->post($annetId)['avskrivning'], $this->post($annetId)['restanse']]);

        // Er dokumentet avskrevet på annen måte før svaret ekspederes, står den avskrivningen.
        $tredje = array_values(array_filter($this->restanser(), static fn (array $p): bool => $p['sak_id'] !== $sakId))[0];
        $kravId = Felt::tekst($tredje['id']);
        $sakId = Felt::tekst($tredje['sak_id']);
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertIsArray($j['journalpost']);
        $sent = Felt::tekst($j['journalpost']['id']);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$kravId/avskriv", ['mate' => 'TLF']);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$sent/ferdigstill", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        $etter = $this->post($kravId);
        self::assertIsArray($etter['avskrivning']);
        self::assertSame(['TLF', null], [$etter['avskrivning']['mate'], $etter['avskrivning']['av']]);
        // Et avskrevet dokument kan ikke få et nytt svar.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertSame([409, 'alt_avskrevet'], [$svar->status, $j['kode']]);
    }

    public function testRegistreringenRettesEtterStatus(): void
    {
        // Journalført (J): bare tittelen og skjermingen kan rettes. Dokumentdatoen og avsenderen står fast.
        $post = $this->restanser()[0];
        $id = Felt::tekst($post['id']);
        self::assertSame('tittel', $post['rediger_felt']);
        self::assertContains('rediger', (array) $post['handlinger']);
        $rettet = [
            'tittel' => 'Høring – rettet tittel', 'offentlig_tittel' => 'Høring', 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'skjerm_part' => true,
            'dokumentdato' => '2026-01-01', 'part_navn' => 'En annen avsender',
        ];
        [$svar, $j] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$id", $rettet);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(
            ['Høring – rettet tittel', 'Høring', '13-1', true, true, $post['dokumentdato'], $post['part_navn']],
            [$j['journalpost']['tittel'], $j['journalpost']['offentlig_tittel'], $j['journalpost']['tilgangskode'], $j['journalpost']['skjerm_tittel'], $j['journalpost']['skjerm_part'], $j['journalpost']['dokumentdato'], $j['journalpost']['part_navn']],
        );
        // Reglene er de samme som ved registreringen: tittelen må være der, og skjerming krever hjemmel.
        [$svar] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$id", ['tittel' => 'ab'] + $rettet);
        self::assertSame(422, $svar->status);
        [$svar, $j] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$id", ['tilgangskode' => ''] + $rettet);
        self::assertSame([422, 'skjerming_uten_hjemmel'], [$svar->status, $j['kode']]);

        // Til journalføring (før J): hele registreringen kan rettes.
        [, $j] = $this->kari->kall('GET', '/api/arkiv/kontroll/journalposter/inngaende');
        $venter = self::rader($j['journalposter'])[0];
        $venterId = Felt::tekst($venter['id']);
        self::assertSame(['alle', 'journalfor'], [$venter['rediger_felt'], $venter['neste']]);
        [$svar, $j] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$venterId", ['tilgangskode' => '', 'skjerm_tittel' => false, 'skjerm_part' => false, 'dokumentdato' => '2026-11-01'] + $rettet);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(['2026-11-01', 'En annen avsender', null], [$j['journalpost']['dokumentdato'], $j['journalpost']['part_navn'], $j['journalpost']['tilgangskode']]);
        [$svar] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$venterId", ['part_navn' => ''] + $rettet);
        self::assertSame(422, $svar->status, 'Et inngående dokument må ha avsender.');

        // Arkivert i en avsluttet mappe: låst. Et dokument under redigering endres på skriveflaten.
        $arkivert = Felt::tekst($this->db->verdi("SELECT j.id FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id WHERE s.status = 'A' LIMIT 1"));
        self::assertSame([null, []], [$this->post($arkivert)['rediger_felt'], $this->post($arkivert)['handlinger']]);
        [$svar, $j] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$arkivert", $rettet);
        self::assertSame([409, 'kan_ikke_rettes'], [$svar->status, $j['kode']]);
        $sakId = Felt::tekst($this->mappe('lonnsforhandlinger-2026')['sak']['id']);
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev());
        self::assertIsArray($j['journalpost']);
        self::assertSame(['rediger_dokument', 'ferdigstill', 'slett'], $j['journalpost']['handlinger']);
        [$svar, $j] = $this->kari->kall('PUT', '/api/arkiv/journalposter/' . Felt::tekst($j['journalpost']['id']), $rettet);
        self::assertSame([409, 'kan_ikke_rettes'], [$svar->status, $j['kode']]);
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        [$svar] = $ola->kall('PUT', "/api/arkiv/journalposter/$id", $rettet);
        self::assertSame(404, $svar->status);
    }

    public function testJournalpostenFlyttesTilEnAnnenMappe(): void
    {
        $fra = $this->mappe('lonnsforhandlinger-2026');
        $til = $this->mappe('horinger-2026');
        $fraId = Felt::tekst($fra['sak']['id']);
        $tilId = Felt::tekst($til['sak']['id']);
        $post = array_values(array_filter($fra['journalposter'], static fn (array $p): bool => $p['restanse'] === true))[0];
        $id = Felt::tekst($post['id']);
        self::assertContains('flytt', (array) $post['handlinger']);

        // Avsluttede mapper, samme mappe og mapper som ikke finnes, kan ikke velges.
        $avsluttet = Felt::tekst($this->db->verdi("SELECT id FROM arkivar_sak WHERE status = 'A' LIMIT 1"));
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => $avsluttet]);
        self::assertSame([409, 'mappe_avsluttet'], [$svar->status, $j['kode']]);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => $fraId]);
        self::assertSame([422, 'samme_mappe'], [$svar->status, $j['kode']]);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => 'finnes-ikke']);
        self::assertSame(422, $svar->status);
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        [$svar] = $ola->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => $tilId]);
        self::assertSame(404, $svar->status);

        // Flyttet: neste ledige dokumentnummer i den nye mappen, og samme løpenummer.
        $siste = max([0, ...array_map(Felt::tall(...), array_column($til['journalposter'], 'postnr'))]);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => $tilId]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame([$tilId, $siste + 1, $post['lopenummer']], [$j['journalpost']['sak_id'], $j['journalpost']['postnr'], $j['journalpost']['lopenummer']]);
        self::assertCount(count($fra['journalposter']) - 1, $this->mappe('lonnsforhandlinger-2026')['journalposter']);
        self::assertCount(count($til['journalposter']) + 1, $this->mappe('horinger-2026')['journalposter']);

        // Avskrivningen følger journalpostene, også på tvers av mapper: et besvart dokument som flyttes, er
        // fortsatt avskrevet av svaret, som ligger igjen i den første mappen.
        $rad = $this->db->en("SELECT a.journalpost_id, a.avskrevet_av FROM arkivar_avskrivning a JOIN arkivar_journalpost j ON j.id = a.journalpost_id JOIN arkivar_sak s ON s.id = j.sak_id WHERE a.arkiv_id = ? AND a.mate = 'BU' AND s.status = 'B' AND j.status = 'J' LIMIT 1", [$this->arkiv]);
        self::assertNotNull($rad);
        $besvart = Felt::tekst($rad['journalpost_id']);
        $opphav = $this->post($besvart)['sak_id'];
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$besvart/flytt", ['sak' => $tilId]);
        self::assertSame(200, $svar->status, $svar->kropp);
        $flyttet = $this->post($besvart);
        self::assertIsArray($flyttet['avskrivning']);
        self::assertIsArray($flyttet['avskrivning']['av']);
        self::assertSame([$rad['avskrevet_av'], $opphav], [$flyttet['avskrivning']['av']['id'], $flyttet['avskrivning']['av']['sak_id']]);

        // Et dokument under redigering kan ikke flyttes, og heller ikke et som er arkivert.
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$fraId/dokumenter", self::brev());
        self::assertIsArray($j['journalpost']);
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/journalposter/' . Felt::tekst($j['journalpost']['id']) . '/flytt', ['sak' => $tilId]);
        self::assertSame([409, 'kan_ikke_flyttes'], [$svar->status, $j['kode']]);
        $arkivert = Felt::tekst($this->db->verdi('SELECT id FROM arkivar_journalpost WHERE sak_id = ? LIMIT 1', [$avsluttet]));
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$arkivert/flytt", ['sak' => $tilId]);
        self::assertSame([409, 'kan_ikke_flyttes'], [$svar->status, $j['kode']]);
    }

    public function testFlyttingOgRettingAvEtDokumentFraPostmottaketVurderesPaaNytt(): void
    {
        // Et dokument fra postmottaket som hører til i en mappe som fulgte med, registrert slik fasiten sier.
        $oppgave = null;
        foreach ((new Oppgavesett())->alle() as $o) {
            $sak = $o['fasit']['sak'];
            if ($sak !== null && $sak['type'] === 'eksisterende' && $o['fasit']['handling'] === 'journalfor' && $o['fasit']['skjerm_tittel'] !== true && $o['fasit']['skjerm_part'] !== true && $o['fasit']['tilgangskode'] === []) {
                $oppgave = $o;
                break;
            }
        }
        self::assertNotNull($oppgave);
        $fasit = $oppgave['fasit'];
        self::assertNotNull($fasit['sak']);
        $riktig = Felt::tekst($this->mappe(Felt::tekst($fasit['sak']['nokkel']))['sak']['id']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$riktig/journalposter", [
            'oppgave' => $oppgave['id'], 'journalfores' => true, 'type' => $fasit['type'] ?? 'I', 'tittel' => (string) $fasit['tittel'], 'dokumentdato' => $oppgave['dokumentdato'],
            'part_navn' => $oppgave['avsender']['navn'], 'tilgangskode' => '', 'skjerm_tittel' => false, 'skjerm_part' => false,
        ]);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertSame($j['maks'], $j['poeng'], 'Fasiten gir full uttelling.');
        self::assertIsArray($j['journalpost']);
        $id = Felt::tekst($j['journalpost']['id']);
        $kriterium = function (string $navn) use ($oppgave): bool {
            [, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/' . $oppgave['id']);
            foreach (self::rader($j['vurdering']) as $k) {
                if ($k['id'] === $navn) {
                    return $k['ok'] === true;
                }
            }
            self::fail("Fant ikke kriteriet $navn.");
        };
        self::assertTrue($kriterium('sak'));

        // Flyttes den til feil mappe, følger vurderingen med. Flyttes den tilbake, er mappen riktig igjen.
        $feil = Felt::tekst($this->db->verdi("SELECT id FROM arkivar_sak WHERE status = 'B' AND id <> ? AND nokkel IS NOT NULL ORDER BY nokkel LIMIT 1", [$riktig]));
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => $feil]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertFalse($kriterium('sak'));
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => $riktig]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertTrue($kriterium('sak'));

        // Rettes registreringen, vurderes den også på nytt: skjerming uten grunn gir avvik.
        [$svar] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$id", ['tittel' => (string) $fasit['tittel'], 'tilgangskode' => '13-1', 'skjerm_tittel' => true, 'offentlig_tittel' => 'Skjermet', 'skjerm_part' => false]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertFalse($kriterium('skjerm_tittel'));
        [$svar] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$id", ['tittel' => (string) $fasit['tittel'], 'tilgangskode' => '', 'skjerm_tittel' => false, 'skjerm_part' => false]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertTrue($kriterium('skjerm_tittel'));
    }

    public function testMappenGaarGjennomStegeneOgSjekklistenStopperAvslutningen(): void
    {
        $mappe = $this->mappe('lonnsforhandlinger-2026');
        $sakId = Felt::tekst($mappe['sak']['id']);
        self::assertSame('B', $mappe['sak']['status']);
        // Bare en mappe under behandling kan sendes til avslutning, og bare en som står til avslutning, tilbake.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/tilbake", []);
        self::assertSame([409, 'ikke_til_avslutning'], [$svar->status, $j['kode']]);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/til-avslutning", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['sak']);
        self::assertSame('S', $j['sak']['status']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/til-avslutning", []);
        self::assertSame([409, 'ikke_under_behandling'], [$svar->status, $j['kode']]);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/tilbake", []);
        self::assertIsArray($j['sak']);
        self::assertSame('B', $j['sak']['status']);
        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        foreach (['til-avslutning', 'tilbake'] as $handling) {
            [$svar] = $ola->kall('POST', "/api/arkiv/saker/$sakId/$handling", []);
            self::assertSame(404, $svar->status);
        }
        [$svar] = $ola->kall('GET', "/api/arkiv/saker/$sakId/sjekkliste");
        self::assertSame(404, $svar->status);

        // Et dokument under redigering og et som ikke er journalført, i tillegg til de to restansene som fulgte med.
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev());
        self::assertIsArray($j['journalpost']);
        $utkast = Felt::tekst($j['journalpost']['id']);
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev());
        self::assertIsArray($j['journalpost']);
        $ekspedert = Felt::tekst($j['journalpost']['id']);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$ekspedert/ferdigstill", []);
        $this->kari->kall('POST', "/api/arkiv/saker/$sakId/til-avslutning", []);

        [$svar, $liste] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId/sjekkliste");
        self::assertSame(200, $svar->status, $svar->kropp);
        $mangler = self::rader($liste['mangler']);
        self::assertSame(['restanse', 'restanse', 'under_redigering', 'ikke_journalfort'], array_column($mangler, 'slag'));
        self::assertFalse($liste['kan_avsluttes']);
        self::assertSame(count($mappe['journalposter']) + 2, $liste['dokumenter']);
        self::assertIsArray($mangler[3]['journalpost']);
        self::assertSame($ekspedert, $mangler[3]['journalpost']['id']);
        // Serveren håndhever det samme: mappen kan ikke avsluttes før listen er tom.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/avslutt", []);
        self::assertSame([409, 'mappe_har_restanser'], [$svar->status, $j['kode']]);

        // Punktene løses ett for ett: journalføring, avskrivning, og utkastet slettes.
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$ekspedert/journalfor", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        [$svar] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$utkast");
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($mangler[0]['journalpost']);
        self::assertIsArray($mangler[1]['journalpost']);
        $this->kari->kall('POST', '/api/arkiv/journalposter/' . Felt::tekst($mangler[0]['journalpost']['id']) . '/avskriv', ['mate' => 'TE']);
        [, $liste] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId/sjekkliste");
        self::assertSame(['restanse'], array_column(self::rader($liste['mangler']), 'slag'));
        // En restanse alene stopper avslutningen.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/avslutt", []);
        self::assertSame([409, 'mappe_har_restanser'], [$svar->status, $j['kode']]);
        $this->kari->kall('POST', '/api/arkiv/journalposter/' . Felt::tekst($mangler[1]['journalpost']['id']) . '/avskriv', ['mate' => 'BE', 'merknad' => 'Besvart i fellesbrevet.']);
        [, $liste] = $this->kari->kall('GET', "/api/arkiv/saker/$sakId/sjekkliste");
        self::assertSame([[], true], [$liste['mangler'], $liste['kan_avsluttes']]);

        // Avsluttet: mappen er A, dokumentene er arkivert, og ingenting kan endres.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/avslutt", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        $etter = $this->mappe('lonnsforhandlinger-2026');
        self::assertSame('A', $etter['sak']['status']);
        foreach ($etter['journalposter'] as $post) {
            self::assertSame(['A', [], null], [$post['status'], $post['handlinger'], $post['neste']]);
        }
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/tilbake", []);
        self::assertSame([409, 'ikke_til_avslutning'], [$svar->status, $j['kode']]);
    }

    public function testJournalforingenKanAngresSammeDag(): void
    {
        [, $j] = $this->kari->kall('GET', '/api/arkiv/kontroll/journalposter/utgaende');
        $post = self::rader($j['journalposter'])[0];
        $id = Felt::tekst($post['id']);
        self::assertSame(['E', 'journalfor'], [$post['status'], $post['neste']]);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/angre-journalforing", []);
        self::assertSame([409, 'kan_ikke_angres'], [$svar->status, $j['kode']]);

        // Journalføringen setter status J og dagens dato. Løpenummeret er det samme som før.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/journalfor", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(['J', '2026-11-20', $post['lopenummer']], [$j['journalpost']['status'], $j['journalpost']['journaldato'], $j['journalpost']['lopenummer']]);
        self::assertIsArray($j['journalpost']['handlinger']);
        self::assertContains('angre_journalforing', $j['journalpost']['handlinger']);

        // Angret: tilbake til statusen og journaldatoen den hadde, og ute av journalen.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/angre-journalforing", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(['E', false, $post['journaldato'], 'journalfor'], [$j['journalpost']['status'], $j['journalpost']['journalfort'], $j['journalpost']['journaldato'], $j['journalpost']['neste']]);
        self::assertSame(8, $this->tall()['utgaende']);

        // Dagen etter kan journalføringen ikke angres: da kan registreringen bare rettes.
        $this->kari->kall('POST', "/api/arkiv/journalposter/$id/journalfor", []);
        $this->db->utfor('UPDATE arkivar_journalpost SET journaldato = ? WHERE id = ?', ['2026-11-19', $id]);
        self::assertNotContains('angre_journalforing', (array) $this->post($id)['handlinger']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/angre-journalforing", []);
        self::assertSame([409, 'kan_ikke_angres'], [$svar->status, $j['kode']]);
        // En registrering som ble journalført da den ble registrert, har ingen journalføring å angre.
        $sakId = Felt::tekst($this->mappe('horinger-2026')['sak']['id']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['type' => 'I', 'tittel' => 'Høring – egen registrering', 'dokumentdato' => '2026-11-20', 'part_navn' => 'Rogaland fylkeskommune']);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertTrue($j['journalpost']['restanse'], 'En ny inngående journalpost er en restanse til den er besvart eller avskrevet.');
        [$svar, $j] = $this->kari->kall('POST', '/api/arkiv/journalposter/' . Felt::tekst($j['journalpost']['id']) . '/angre-journalforing', []);
        self::assertSame([409, 'kan_ikke_angres'], [$svar->status, $j['kode']]);
    }

    public function testEnAvsluttetMappeErLaastForAlleEndringer(): void
    {
        // Et svar på en restanse, ferdigstilt og journalført; den andre restansen avskrives; mappen avsluttes.
        $mappe = $this->mappe('lonnsforhandlinger-2026');
        $sakId = Felt::tekst($mappe['sak']['id']);
        [$krav, $annet] = array_values(array_filter($mappe['journalposter'], static fn (array $p): bool => $p['restanse'] === true));
        $kravId = Felt::tekst($krav['id']);
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertIsArray($j['journalpost']);
        $svarId = Felt::tekst($j['journalpost']['id']);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/ferdigstill", []);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/journalfor", []);
        $this->kari->kall('POST', '/api/arkiv/journalposter/' . Felt::tekst($annet['id']) . '/avskriv', ['mate' => 'TE']);
        // En egen registrering uten dokument og et dokument fra postmottaket ligger også i mappen.
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['type' => 'U', 'tittel' => 'Egen registrering', 'dokumentdato' => '2026-11-20', 'part_navn' => 'NITO Sirevåg']);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        $egen = Felt::tekst($j['journalpost']['id']);
        self::assertContains('slett', (array) $j['journalpost']['handlinger'], 'En registrering uten dokument kan slettes mens mappen er åpen.');
        $oppgave = (new Oppgavesett())->alle()[0];
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", [
            'oppgave' => $oppgave['id'], 'journalfores' => true, 'type' => 'I', 'tittel' => 'Fra postmottaket', 'dokumentdato' => $oppgave['dokumentdato'], 'part_navn' => $oppgave['avsender']['navn'],
        ]);
        self::assertSame(201, $svar->status, $svar->kropp);
        $fraPostmottak = Felt::tekst($this->db->verdi('SELECT journalpost_id FROM arkivar_oppgavestatus WHERE oppgave_id = ?', [$oppgave['id']]));
        $this->kari->kall('POST', "/api/arkiv/journalposter/$fraPostmottak/avskriv", ['mate' => 'TE']);
        $this->kari->kall('POST', "/api/arkiv/saker/$sakId/til-avslutning", []);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/avslutt", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        $for = $this->mappe('lonnsforhandlinger-2026');
        self::assertSame('A', $for['sak']['status']);
        $annenMappe = Felt::tekst($this->mappe('horinger-2026')['sak']['id']);

        // Ingen av rutene som endrer noe, slipper gjennom.
        $forsok = [
            ['DELETE', "/api/arkiv/journalposter/$svarId", null],
            ['DELETE', "/api/arkiv/journalposter/$egen", null],
            ['DELETE', '/api/arkiv/postmottak/' . $oppgave['id'], null],
            ['POST', "/api/arkiv/journalposter/$svarId/angre-journalforing", []],
            ['PUT', "/api/arkiv/journalposter/$kravId", ['tittel' => 'Rettet', 'tilgangskode' => '', 'skjerm_tittel' => false, 'skjerm_part' => false]],
            ['POST', '/api/arkiv/journalposter/' . Felt::tekst($annet['id']) . '/avskriv', ['mate' => 'TO']],
            ['DELETE', '/api/arkiv/journalposter/' . Felt::tekst($annet['id']) . '/avskrivning', null],
            ['DELETE', "/api/arkiv/journalposter/$kravId/avskrivning", null],
            ['POST', "/api/arkiv/journalposter/$kravId/flytt", ['sak' => $annenMappe]],
            ['POST', "/api/arkiv/journalposter/$svarId/flytt", ['sak' => $annenMappe]],
            ['POST', "/api/arkiv/journalposter/$svarId/journalfor", []],
            ['POST', "/api/arkiv/saker/$sakId/journalposter", ['type' => 'I', 'tittel' => 'Ny registrering', 'dokumentdato' => '2026-11-20', 'part_navn' => 'NITO Sirevåg']],
            ['POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev()],
            ['GET', "/api/arkiv/journalposter/$kravId/svar", null],
            ['POST', "/api/arkiv/saker/$sakId/tilbake", []],
            ['POST', "/api/arkiv/saker/$sakId/til-avslutning", []],
            ['POST', "/api/arkiv/saker/$sakId/kontroller", []],
            ['POST', "/api/arkiv/saker/$sakId/avslutt", []],
            ['DELETE', "/api/arkiv/saker/$sakId", null],
        ];
        foreach ($forsok as [$metode, $sti, $kropp]) {
            [$svar] = $kropp === null ? $this->kari->kall($metode, $sti) : $this->kari->kall($metode, $sti, $kropp);
            self::assertSame(409, $svar->status, "$metode $sti: " . $svar->kropp);
        }
        // Mappen står som før: de samme journalpostene, arkivert, med avskrivningene sine, og uten handlinger.
        $etter = $this->mappe('lonnsforhandlinger-2026');
        self::assertSame(array_column($for['journalposter'], 'id'), array_column($etter['journalposter'], 'id'));
        foreach ($etter['journalposter'] as $post) {
            self::assertSame(['A', [], null, false], [$post['status'], $post['handlinger'], $post['neste'], $post['kan_slettes']]);
            self::assertSame($post['type'] === 'I', $post['avskrivning'] !== null, 'Alle inngående dokumenter i den avsluttede mappen er avskrevet.');
        }
        self::assertSame([], $this->post($svarId)['handlinger']);
        [, $j] = $this->kari->kall('GET', '/api/arkiv/postmottak/' . $oppgave['id']);
        self::assertIsArray($j['journalpost'], 'Behandlingen av dokumentet fra postmottaket står.');
    }

    public function testSvaretIEnAnnenMappeAapnerIkkeDenAvsluttedeMappen(): void
    {
        // Svaret flyttes til en annen mappe før mappen med det inngående dokumentet avsluttes.
        $mappe = $this->mappe('lonnsforhandlinger-2026');
        $sakId = Felt::tekst($mappe['sak']['id']);
        [$krav, $annet] = array_values(array_filter($mappe['journalposter'], static fn (array $p): bool => $p['restanse'] === true));
        $kravId = Felt::tekst($krav['id']);
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertIsArray($j['journalpost']);
        $svarId = Felt::tekst($j['journalpost']['id']);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/ferdigstill", []);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/journalfor", []);
        $annenMappe = Felt::tekst($this->mappe('horinger-2026')['sak']['id']);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/flytt", ['sak' => $annenMappe]);
        self::assertSame(200, $svar->status, $svar->kropp);
        // Før avslutningen kan journalføringen av svaret angres (samme dag).
        self::assertContains('angre_journalforing', (array) $this->post($svarId)['handlinger']);
        $this->kari->kall('POST', '/api/arkiv/journalposter/' . Felt::tekst($annet['id']) . '/avskriv', ['mate' => 'TE']);
        $this->kari->kall('POST', "/api/arkiv/saker/$sakId/til-avslutning", []);
        [$svar] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/avslutt", []);
        self::assertSame(200, $svar->status, $svar->kropp);

        // Svaret ligger i en åpen mappe, men avskriver et arkivert dokument: det kan verken slettes eller tas ut av
        // journalen. Tittelen kan fortsatt rettes, og det kan flyttes.
        $vist = $this->post($svarId);
        self::assertNotContains('angre_journalforing', (array) $vist['handlinger']);
        self::assertNotContains('slett', (array) $vist['handlinger']);
        self::assertContains('rediger', (array) $vist['handlinger']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$svarId/angre-journalforing", []);
        self::assertSame([409, 'avskriver_arkivert'], [$svar->status, $j['kode']]);
        [$svar, $j] = $this->kari->kall('DELETE', "/api/arkiv/journalposter/$svarId");
        self::assertSame([409, 'kan_ikke_slettes'], [$svar->status, $j['kode']]);
        $arkivert = $this->post($kravId);
        self::assertIsArray($arkivert['avskrivning']);
        self::assertIsArray($arkivert['avskrivning']['av']);
        self::assertSame(['A', 'BU', $svarId], [$arkivert['status'], $arkivert['avskrivning']['mate'], $arkivert['avskrivning']['av']['id']]);
        self::assertSame('J', $this->post($svarId)['status']);
    }

    public function testArkivertUtenJournalforingKanRettesOgFlyttesIEnAapenMappe(): void
    {
        // «Arkiveres uten journalføring» gir status A uten at journalposten står i journalen. I en åpen mappe er
        // den ikke låst: den kan rettes og flyttes, og en egen registrering kan slettes.
        $sakId = Felt::tekst($this->mappe('lonnsforhandlinger-2026')['sak']['id']);
        $til = Felt::tekst($this->mappe('horinger-2026')['sak']['id']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['journalfores' => false, 'type' => 'I', 'tittel' => 'Årsmelding', 'dokumentdato' => '2026-11-20', 'part_navn' => '']);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        $id = Felt::tekst($j['journalpost']['id']);
        self::assertSame(['A', false, 'alle', false], [$j['journalpost']['status'], $j['journalpost']['journalfort'], $j['journalpost']['rediger_felt'], $j['journalpost']['restanse']]);
        self::assertSame(['rediger', 'flytt', 'slett'], $j['journalpost']['handlinger']);
        [$svar, $j] = $this->kari->kall('PUT', "/api/arkiv/journalposter/$id", ['tittel' => 'Årsmelding 2025', 'dokumentdato' => '2026-11-19', 'part_navn' => '', 'tilgangskode' => '', 'skjerm_tittel' => false, 'skjerm_part' => false]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(['Årsmelding 2025', '2026-11-19', 'A'], [$j['journalpost']['tittel'], $j['journalpost']['dokumentdato'], $j['journalpost']['status']]);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/flytt", ['sak' => $til]);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame($til, $j['journalpost']['sak_id']);

        // Et dokument fra postmottaket som er arkivert uten journalføring i feil mappe, kan flyttes til en annen.
        $oppgave = (new Oppgavesett())->alle()[0];
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/journalposter", ['oppgave' => $oppgave['id'], 'journalfores' => false, 'tittel' => 'Arkivert fra postmottaket', 'dokumentdato' => $oppgave['dokumentdato']]);
        self::assertSame(201, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(['rediger', 'flytt'], $j['journalpost']['handlinger']);
        [$svar] = $this->kari->kall('POST', '/api/arkiv/journalposter/' . Felt::tekst($j['journalpost']['id']) . '/flytt', ['sak' => $til]);
        self::assertSame(200, $svar->status, $svar->kropp);

        // Arkivert fra journalen (mappen er avsluttet) er fortsatt låst.
        $arkivert = $this->db->en("SELECT j.id FROM arkivar_journalpost j JOIN arkivar_sak s ON s.id = j.sak_id WHERE s.status = 'A' AND j.status = 'A' LIMIT 1");
        self::assertNotNull($arkivert);
        self::assertSame([[], null], [$this->post(Felt::tekst($arkivert['id']))['handlinger'], $this->post(Felt::tekst($arkivert['id']))['rediger_felt']]);
    }

    public function testEnAnnenStudentNaarIkkeArbeidsflytenIArkivet(): void
    {
        $mappe = $this->mappe('lonnsforhandlinger-2026');
        $sakId = Felt::tekst($mappe['sak']['id']);
        $krav = array_values(array_filter($mappe['journalposter'], static fn (array $p): bool => $p['restanse'] === true))[0];
        $kravId = Felt::tekst($krav['id']);
        $avskrevet = Felt::tekst($this->db->verdi("SELECT journalpost_id FROM arkivar_avskrivning WHERE arkiv_id = ? AND mate = 'TE' LIMIT 1", [$this->arkiv]));
        [, $j] = $this->kari->kall('GET', '/api/arkiv/kontroll/journalposter/utgaende');
        $journalfort = Felt::tekst(self::rader($j['journalposter'])[0]['id']);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$journalfort/journalfor", []);

        $ola = $this->student('ola@hogskolen.example', 'Ola Student', null, '10.0.0.2');
        [$svar] = $ola->kall('GET', '/api/arkiv');
        self::assertSame(200, $svar->status);
        foreach ([['GET', "/api/arkiv/journalposter/$kravId/svar"], ['DELETE', "/api/arkiv/journalposter/$avskrevet/avskrivning"], ['POST', "/api/arkiv/journalposter/$journalfort/angre-journalforing"]] as [$metode, $sti]) {
            [$svar] = $metode === 'POST' ? $ola->kall($metode, $sti, []) : $ola->kall($metode, $sti);
            self::assertSame(404, $svar->status, "$metode $sti");
        }
        // En journalpost kan ikke flyttes til en annen students mappe, og et svar kan ikke gjelde en annen students dokument.
        $olasMappe = Felt::tekst($this->db->verdi("SELECT id FROM arkivar_sak WHERE arkiv_id <> ? AND nokkel = 'horinger-2026'", [$this->arkiv]));
        [$svar] = $this->kari->kall('POST', "/api/arkiv/journalposter/$kravId/flytt", ['sak' => $olasMappe]);
        self::assertSame(422, $svar->status, $svar->kropp);
        [$svar] = $ola->kall('POST', "/api/arkiv/saker/$olasMappe/dokumenter", self::brev() + ['svar_paa' => $kravId]);
        self::assertSame(422, $svar->status, $svar->kropp);
        // Karis arkiv er urørt.
        self::assertSame($sakId, $this->post($kravId)['sak_id']);
        self::assertNotNull($this->post($avskrevet)['avskrivning']);
        self::assertSame('J', $this->post($journalfort)['status']);
    }

    public function testAngretJournalforingAvEgetDokumentGirJournaldatoenFraFor(): void
    {
        // Et dokument studenten skrev i går og journalfører i dag: angres journalføringen, er journaldatoen igjen
        // dagen journalposten ble opprettet, ikke dagens dato.
        $sakId = Felt::tekst($this->mappe('lonnsforhandlinger-2026')['sak']['id']);
        [, $j] = $this->kari->kall('POST', "/api/arkiv/saker/$sakId/dokumenter", self::brev());
        self::assertIsArray($j['journalpost']);
        $id = Felt::tekst($j['journalpost']['id']);
        $this->kari->kall('POST', "/api/arkiv/journalposter/$id/ferdigstill", []);
        // Opprettet 18.11.2026 klokka 23.30 UTC, som er 19.11. i norsk tid.
        $this->db->utfor('UPDATE arkivar_journalpost SET opprettet = ?, journaldato = ? WHERE id = ?', ['2026-11-18 23:30:00.000000', '2026-11-19', $id]);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/journalfor", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame('2026-11-20', $j['journalpost']['journaldato']);
        [$svar, $j] = $this->kari->kall('POST', "/api/arkiv/journalposter/$id/angre-journalforing", []);
        self::assertSame(200, $svar->status, $svar->kropp);
        self::assertIsArray($j['journalpost']);
        self::assertSame(['E', '2026-11-19', false], [$j['journalpost']['status'], $j['journalpost']['journaldato'], $j['journalpost']['journalfort']]);
    }

    public function testSvartittelenFaarLitenForbokstavUnntattForForkortelserOgTall(): void
    {
        $maler = new \ArkIvar\Arkiv\Dokumentmaler();
        self::assertSame('Svar på klage over delvis avslag på innsyn', $maler->svartittel('Klage over delvis avslag på innsyn'));
        self::assertSame('Svar på FAU – innspill til skoleruta', $maler->svartittel('FAU – innspill til skoleruta'));
        self::assertSame('Svar på 17. mai – søknad om støtte', $maler->svartittel('17. mai – søknad om støtte'));
        self::assertSame('Svar på øvingstider i hallen', $maler->svartittel('Øvingstider i hallen'));
        self::assertFalse($maler->hent('forelopig-svar')['avskriver'] ?? true, 'Et foreløpig svar avskriver ikke.');
        self::assertTrue($maler->hent('utgaende-brev')['avskriver'] ?? false);
        self::assertContains(Kodelister::AVSKR_SAK_AVSLUTTET, Kodelister::AVSKRIVNINGSMATER);
        self::assertNotNull((new Grunndata())->startpost('lonnsforhandlinger-2026#1'));
    }
}
