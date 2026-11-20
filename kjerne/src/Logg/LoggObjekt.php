<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * Objektet en hendelse gjelder: app, type (kode) og typens klartekst, ID og navn i klartekst (BIV-LOG-02).
 */
final class LoggObjekt
{
    public function __construct(
        public readonly ?string $app,
        public readonly string $type,
        public readonly string $typeTekst,
        public readonly ?string $id,
        public readonly string $navn,
    ) {
    }

    /** @return array{app: ?string, type: string, type_tekst: string, id: ?string, navn: string} */
    public function tilListe(): array
    {
        return ['app' => $this->app, 'type' => $this->type, 'type_tekst' => $this->typeTekst, 'id' => $this->id, 'navn' => $this->navn];
    }
}
