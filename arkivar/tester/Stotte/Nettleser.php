<?php

declare(strict_types=1);

namespace ArkIvar\Tester\Stotte;

use Bivrost\Kjerne\Http\Foresporsel;
use Bivrost\Kjerne\Http\Svar;

/**
 * En nettleser i testene: husker informasjonskapslene og CSRF-nøkkelen mellom kallene, slik at flere personer
 * (student, lærer, administrator, drift) kan være logget inn samtidig i samme test.
 */
final class Nettleser
{
    /** @var array<string, string> */
    private array $kapsler = [];

    public string $csrf = '';

    /** @param \Closure(Foresporsel): Svar $handter Sender forespørselen gjennom Kjerne og appen. */
    public function __construct(private readonly \Closure $handter, private readonly string $ip = '127.0.0.1')
    {
    }

    /**
     * @param array<string, mixed>|null $kropp
     * @param array<string, string> $hoder
     * @return array{0: Svar, 1: array<string, mixed>}
     */
    public function kall(string $metode, string $sti, ?array $kropp = null, array $hoder = []): array
    {
        // Grensesnittet ber alltid om JSON; da svarer Kjerne med RFC 9457 også for feil utenfor /bivrost/api/.
        $hoder += ['accept' => 'application/json'] + ($kropp !== null ? ['content-type' => 'application/json'] : []) + ($this->csrf !== '' ? ['x-csrf' => $this->csrf] : []);
        $query = [];
        if (str_contains($sti, '?')) {
            [$sti, $sporring] = explode('?', $sti, 2);
            parse_str($sporring, $deler);
            foreach ($deler as $navn => $verdi) {
                $query[(string) $navn] = is_string($verdi) ? $verdi : '';
            }
        }
        $svar = ($this->handter)(new Foresporsel($metode, $sti, $hoder, $query, $this->kapsler, $kropp === null ? '' : (string) json_encode($kropp), $this->ip, true, 'localhost'));
        foreach ($svar->kapsler() as $kapsel) {
            [$par] = explode(';', $kapsel, 2);
            [$navn, $verdi] = explode('=', $par, 2);
            if ($verdi === '') {
                unset($this->kapsler[$navn]);
            } else {
                $this->kapsler[$navn] = $verdi;
            }
        }
        $json = json_decode($svar->kropp, true);
        $ut = [];
        foreach (is_array($json) ? $json : [] as $nokkel => $verdi) {
            $ut[(string) $nokkel] = $verdi;
        }
        if (is_string($ut['csrf'] ?? null)) {
            $this->csrf = $ut['csrf'];
        }

        return [$svar, $ut];
    }

    /** Henter økten (og dermed CSRF-nøkkelen), som grensesnittet gjør når siden åpnes. */
    public function start(): void
    {
        $this->kall('GET', '/bivrost/api/okt');
    }
}
