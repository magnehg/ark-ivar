<?php

declare(strict_types=1);

namespace Bivrost\Kjerne;

use Psr\Clock\ClockInterface;

/** Systemklokken i UTC (PSR-20). Testene bruker en fast klokke. */
final class Klokke implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
