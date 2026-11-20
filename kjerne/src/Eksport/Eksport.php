<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Eksport;

use Bivrost\Kjerne\Database\Felt;
use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Konfig\AppKonfig;
use Bivrost\Kjerne\Kontekst;
use Bivrost\Kjerne\Logg\Hendelse;
use Bivrost\Kjerne\Logg\Logg;
use Bivrost\Kjerne\Logg\LoggFilter;
use Bivrost\Kjerne\Tilgang\Rolleregister;

/**
 * Eksport per app som JSON med klartekst (BIV-EKS-01–03): formatet «bivrost-eksport» versjon 1 med appen, hvem som
 * lagde den, rolleregisteret, tilgangshistorikken (hendelser om innlogging, tilganger og rolleregisteret), hele
 * Bivrost-loggen for appen, og appens egne data (EksportBidrag). Skrives til en midlertidig fil i driftsmappen og
 * sendes først når alt er skrevet, så en feil aldri gir en avkuttet fil. Ingen passordhasher, nøkler eller tokener.
 */
final class Eksport
{
    public function __construct(
        private readonly AppKonfig $app,
        private readonly Logg $logg,
        private readonly Rolleregister $roller,
        private readonly Kontekst $kontekst,
        private readonly ?EksportBidrag $bidrag,
        /** @var array<string, ?string> */
        private readonly array $versjoner,
    ) {
    }

    /** Skriver eksporten og gir stien til filen (som kalleren sender og sletter). */
    public function lag(): string
    {
        $mappe = $this->app->drift . '/eksport';
        if (!is_dir($mappe) && !@mkdir($mappe, 0750, true) && !is_dir($mappe)) {
            throw new BivrostFeil('bivrost.feil.eksport.skriv');
        }
        foreach (Felt::ellers(glob($mappe . '/eksport-*.json'), []) as $gammel) {
            if (filemtime($gammel) < time() - 3600) {
                @unlink($gammel);
            }
        }
        $fil = $mappe . '/eksport-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        try {
            @set_time_limit(300);
            $s = new JsonStrom($fil);
            $a = $this->kontekst->aktor();
            $s->apneObjekt();
            $s->verdi('bivrost-eksport', 'format');
            $s->verdi(1, 'formatversjon');
            $s->verdi(gmdate('Y-m-d\TH:i:s\Z'), 'laget');
            $s->verdi(['type' => $a->type, 'type_tekst' => $this->logg->tekst('bivrost.aktortype.' . $a->type), 'id' => $a->id, 'navn' => $a->navn, 'tittel' => $a->tittel], 'laget_av');
            $s->verdi(['id' => $this->app->app, 'navn' => $this->app->appnavn], 'app');
            $s->verdi($this->versjoner, 'versjoner');
            $s->verdi($this->app->hovedsprak, 'sprak');
            $s->verdi($this->roller->dokument(), 'rolleregister');
            $s->apneListe('tilgangshistorikk');
            $tilgang = static fn (Hendelse $h): bool => (bool) preg_match('/^urn:bivrost:hendelse:(innlogging|utlogging|tilgang|rolleregister|okt):/', $h->hva['urn']);
            $this->logg->lager()->hverEn(new LoggFilter(app: $this->app->app), static function (Hendelse $h) use ($s, $tilgang): void {
                if ($tilgang($h)) {
                    $s->verdi($h->tilListe());
                }
            });
            $s->lukk();
            $s->apneListe('logg');
            $this->logg->lager()->hverEn(new LoggFilter(app: $this->app->app), static function (Hendelse $h) use ($s): void {
                $s->verdi($h->tilListe());
            });
            $s->lukk();
            if ($this->bidrag !== null) {
                [$format, $versjon] = $this->bidrag->format();
                $s->verdi(['format' => $format, 'formatversjon' => $versjon], 'app_format');
                $s->apneObjekt('app_data');
                $this->bidrag->skriv($s);
                $s->lukk();
            }
            $s->lukk();
            $s->ferdig();
        } catch (\Throwable $e) {
            @unlink($fil);
            throw $e instanceof BivrostFeil ? $e : new BivrostFeil('bivrost.feil.eksport.skriv', [], $e);
        }
        @chmod($fil, 0640);
        $this->logg->skriv('drift:eksport', null, [], [], ['storrelse' => (int) filesize($fil)]);

        return $fil;
    }
}
