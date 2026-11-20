<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Kontekst;
use Bivrost\Kjerne\Krypto\Tilfeldig;
use Bivrost\Kjerne\Sprak\Klartekst;
use Psr\Clock\ClockInterface;

/**
 * Skriver hendelser i det felles formatet (BIV-LOG-01–06). Bivrost eier mekanismen og sin egen logg (innlogging,
 * økter, tilganger, rolleregisteret, drift); appen eier fagloggen og kan bruke den samme klassen med sitt eget lager.
 *
 * Klarteksten (hendelsestypen, aktørtypen, kilden, kategorien og appens navn) settes inn her fra språkfilen på
 * hovedspråket. Nøkler i «data» som ser ut som hemmeligheter (passord, token, nøkkel, hemmelighet, secret, key),
 * erstattes med «[skjult]», så en feil i en kaller ikke legger en hemmelighet i loggen (BIV-SIK-04).
 */
final class Logg
{
    private const HEMMELIG = '/passord|password|token|nokkel|nøkkel|hemmelig|secret|key|kode_hash|signatur/i';

    public function __construct(
        private readonly LoggLager $lager,
        private readonly Klartekst $klartekst,
        private readonly Kontekst $kontekst,
        private readonly AppKonfig $app,
        private readonly ClockInterface $klokke,
    ) {
    }

    /**
     * @param string $hva Hendelsestypen: «<område>:<handling>» (blir urn:bivrost:hendelse:…) eller en hel URN.
     * @param array<string, array{kode: scalar|null, tekst: string}> $for
     * @param array<string, array{kode: scalar|null, tekst: string}> $etter
     * @param array<string, mixed> $data
     * @param list<string>|null $apper Appene hendelsen gjelder; null = denne appen, [] = på tvers.
     */
    public function skriv(
        string $hva,
        ?LoggObjekt $objekt = null,
        array $for = [],
        array $etter = [],
        array $data = [],
        string $kategori = 'revisjon',
        ?array $apper = null,
        ?Aktor $hvem = null,
    ): Hendelse {
        $urn = str_starts_with($hva, 'urn:') ? $hva : 'urn:bivrost:hendelse:' . $hva;
        $a = $hvem ?? $this->kontekst->aktor();
        $apper ??= [$this->app->app];
        $h = new Hendelse(
            Tilfeldig::uuid(),
            $this->klokke->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            $this->klartekst->kode('loggkategori', $kategori),
            $this->klartekst->hendelse($urn),
            [
                'type' => $a->type,
                'type_tekst' => $this->klartekst->kode('aktortype', $a->type)['tekst'],
                'id' => $a->id,
                'navn' => $a->navn,
                'tittel' => $a->tittel,
            ],
            $objekt?->tilListe(),
            $for,
            $etter,
            array_map(fn (string $id): array => ['id' => $id, 'navn' => $this->appnavn($id)], array_values(array_unique($apper))),
            $this->klartekst->kode('kilde', $this->kontekst->kilde()),
            $this->kontekst->korrelasjon(),
            self::skjul($data),
        );
        $this->lager->skriv($h);

        return $h;
    }

    /**
     * Som skriv(), men en feil i loggen stopper ikke det som ble gjort (brukes for mislykkede forsøk o.l., der
     * hovedhandlingen alt har feilet). Feilen skrives i serverens feillogg uten detaljer fra hendelsen.
     *
     * @param array<string, mixed> $data
     */
    public function skrivTrygt(string $hva, ?LoggObjekt $objekt = null, array $data = [], string $kategori = 'sikkerhet'): void
    {
        try {
            $this->skriv($hva, $objekt, [], [], $data, $kategori);
        } catch (\Throwable $e) {
            error_log('Bivrost: fikk ikke skrevet i loggen (' . get_class($e) . ').');
        }
    }

    public function korrelasjon(): string
    {
        return $this->kontekst->korrelasjon();
    }

    public function fortsettKorrelasjon(string $id): void
    {
        $this->kontekst->fortsettKorrelasjon($id);
    }

    /**
     * En tekst på hovedspråket (klarteksten i loggen).
     *
     * @param array<string, scalar|null> $verdier
     */
    public function tekst(string $nokkel, array $verdier = []): string
    {
        return $this->klartekst->tekst($nokkel, $verdier);
    }

    public function klartekst(): Klartekst
    {
        return $this->klartekst;
    }

    /**
     * Loggen som JSON Lines (én hendelse per linje, BIV-LOG-06) i en midlertidig fil, eventuelt som CloudEvents.
     * Gir stien; kalleren sender og sletter filen.
     */
    public function eksporterJsonl(string $mappe, LoggFilter $filter, bool $cloudEvents = false): string
    {
        if (!is_dir($mappe) && !@mkdir($mappe, 0750, true) && !is_dir($mappe)) {
            throw new \Bivrost\Kjerne\Feil\BivrostFeil('bivrost.feil.eksport.skriv');
        }
        $fil = $mappe . '/logg-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jsonl';
        $f = fopen($fil, 'xb');
        if ($f === false) {
            throw new \Bivrost\Kjerne\Feil\BivrostFeil('bivrost.feil.eksport.skriv');
        }
        try {
            $this->lager->hverEn($filter, function (Hendelse $h) use ($f, $cloudEvents): void {
                $d = $cloudEvents ? CloudEvent::fraHendelse($h, $this->app->app) : $h->tilListe();
                fwrite($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            });
        } finally {
            fclose($f);
        }
        $this->skriv('drift:eksport', null, [], [], ['format' => $cloudEvents ? 'cloudevents+jsonl' : 'jsonl']);

        return $fil;
    }

    public function lager(): LoggLager
    {
        return $this->lager;
    }

    private function appnavn(string $id): string
    {
        return $id === $this->app->app ? $this->app->appnavn : ($id === 'bivrost' ? 'Bivrost' : $id);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function skjul(array $data): array
    {
        $ut = [];
        foreach ($data as $k => $v) {
            $k = (string) $k;
            if (preg_match(self::HEMMELIG, $k) === 1 && !str_ends_with($k, '_navn')) {
                $ut[$k] = '[skjult]';
            } elseif (is_array($v)) {
                $ut[$k] = self::skjul($v);
            } else {
                $ut[$k] = $v;
            }
        }

        return $ut;
    }
}
