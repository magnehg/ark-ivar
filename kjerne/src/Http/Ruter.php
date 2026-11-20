<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

/**
 * En enkel ruter: metode + sti med {navn}-deler (bare [A-Za-z0-9._-]). Gir null når ingen rute passer, så appen kan
 * ta resten. 405 når stien finnes, men ikke med metoden.
 */
final class Ruter
{
    /** @var list<array{metode: string, monster: string, navn: list<string>, fn: callable(Foresporsel, array<string, string>): Svar}> */
    private array $ruter = [];

    /** @param callable(Foresporsel, array<string, string>): Svar $fn */
    public function legg(string $metode, string $sti, callable $fn): void
    {
        $navn = [];
        $monster = preg_replace_callback('/\\\\\{([a-z_]+)\\\\\}/', static function (array $m) use (&$navn): string {
            $navn[] = $m[1];

            return '([A-Za-z0-9._-]+)';
        }, preg_quote($sti, '#'));
        $this->ruter[] = ['metode' => $metode, 'monster' => '#^' . $monster . '$#D', 'navn' => $navn, 'fn' => $fn];
    }

    public function handter(Foresporsel $f): ?Svar
    {
        $stiFinnes = false;
        foreach ($this->ruter as $r) {
            if (preg_match($r['monster'], $f->sti, $m) !== 1) {
                continue;
            }
            $stiFinnes = true;
            if ($r['metode'] !== $f->metode && !($r['metode'] === 'GET' && $f->metode === 'HEAD')) {
                continue;
            }
            $verdier = [];
            foreach ($r['navn'] as $i => $n) {
                $verdier[$n] = $m[$i + 1];
            }

            return ($r['fn'])($f, $verdier);
        }

        return $stiFinnes ? Svar::json(['kode' => 'metode_ikke_tillatt'], 405) : null;
    }
}
