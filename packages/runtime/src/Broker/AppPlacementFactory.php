<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

final readonly class AppPlacementFactory
{
    private const int MAX_AUTO_WORKERS = 4;

    public function __construct(private CpuCountDetector $cpus) {}

    public function createPlacementForHost(): AppPlacement
    {
        return new AppPlacement(min(self::MAX_AUTO_WORKERS, $this->cpus->detectCpuCount()));
    }
}
