<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

use Psr\Clock\ClockInterface;

/** En klokke testene styrer. */
final class FastKlokke implements ClockInterface
{
    private \DateTimeImmutable $naa;

    public function __construct(string $tid = '2026-11-13 10:00:00')
    {
        $this->naa = new \DateTimeImmutable($tid, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->naa;
    }

    public function frem(int $sekunder): void
    {
        $this->naa = $this->naa->modify("+$sekunder seconds");
    }

    public function sett(string $tid): void
    {
        $this->naa = new \DateTimeImmutable($tid, new \DateTimeZone('UTC'));
    }
}
