<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

/** En versjon (release) av en pakke på GitHub. */
final class Utgivelse
{
    /**
     * @param array<string, int> $ressurser Navn → ID hos GitHub.
     * @param array<string, mixed>|null $info Innholdet i <tag>.versjon.json (hentet ved planleggingen).
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $versjon,
        public readonly bool $forhandsversjon,
        public readonly array $ressurser,
        public readonly ?string $digest = null,
        public readonly ?string $publisert = null,
        public ?array $info = null,
    ) {
    }

    public function zip(): string
    {
        return $this->tag . '.zip';
    }

    public function har(string $navn): bool
    {
        return isset($this->ressurser[$navn]);
    }
}
