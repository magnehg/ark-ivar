<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Eksport;

use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Skriver JSON bit for bit til en fil, så en stor eksport ikke må ligge i minnet (128 MB på et webhotell).
 * Gyldig JSON uansett innhold: nøkler og verdier går gjennom json_encode.
 */
final class JsonStrom
{
    /** @var resource */
    private $f;

    /** @var list<array{type: string, forste: bool}> */
    private array $stabel = [];

    public function __construct(string $fil)
    {
        $f = @fopen($fil, 'xb');
        if ($f === false) {
            throw new BivrostFeil('bivrost.feil.eksport.skriv');
        }
        $this->f = $f;
    }

    private function skriv(string $s): void
    {
        if (fwrite($this->f, $s) !== strlen($s)) {
            throw new BivrostFeil('bivrost.feil.eksport.skriv');
        }
    }

    private function skille(): void
    {
        $n = count($this->stabel);
        if ($n === 0) {
            return;
        }
        if (!$this->stabel[$n - 1]['forste']) {
            $this->skriv(',');
        }
        $this->stabel[$n - 1]['forste'] = false;
    }

    public function apneObjekt(?string $nokkel = null): void
    {
        $this->nokkel($nokkel);
        $this->skriv('{');
        $this->stabel[] = ['type' => 'objekt', 'forste' => true];
    }

    public function apneListe(?string $nokkel = null): void
    {
        $this->nokkel($nokkel);
        $this->skriv('[');
        $this->stabel[] = ['type' => 'liste', 'forste' => true];
    }

    public function verdi(mixed $v, ?string $nokkel = null): void
    {
        $this->nokkel($nokkel);
        $this->skriv(json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function nokkel(?string $nokkel): void
    {
        $this->skille();
        $topp = $this->stabel[count($this->stabel) - 1]['type'] ?? null;
        if ($topp === 'objekt') {
            if ($nokkel === null) {
                throw new \LogicException('Et felt i et objekt må ha en nøkkel.');
            }
            $this->skriv(json_encode($nokkel, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . ':');
        }
    }

    public function lukk(): void
    {
        $t = array_pop($this->stabel);
        if ($t === null) {
            throw new \LogicException('Ingenting å lukke.');
        }
        $this->skriv($t['type'] === 'objekt' ? '}' : ']');
    }

    public function ferdig(): void
    {
        if ($this->stabel !== []) {
            throw new \LogicException('JSON-strømmen er ikke lukket.');
        }
        if (!fflush($this->f) || !fclose($this->f)) {
            throw new BivrostFeil('bivrost.feil.eksport.skriv');
        }
    }
}
