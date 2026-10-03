<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;

final readonly class ServiceCallPolicy
{
    public function __construct(
        public int $perWorker,
        public int $total,
        public bool $dryRun = false,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $serviceCalls): self
    {
        return new self(
            perWorker: $serviceCalls->readInt('max_in_flight_per_worker'),
            total: $serviceCalls->readInt('max_in_flight'),
            dryRun: $serviceCalls->readBool('dry_run'),
        );
    }

    public function limitsPerWorker(): bool
    {
        return $this->perWorker > 0;
    }

    public function boundsTotal(): bool
    {
        return $this->total > 0;
    }
}
