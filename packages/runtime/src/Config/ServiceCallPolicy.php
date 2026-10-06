<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Model\CorrelationId;

final readonly class ServiceCallPolicy
{
    private const string DRY_RUN_CONTEXT_PREFIX = 'dry-run:';

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

    public function createDryRunContext(CorrelationId $correlationId): EventContext
    {
        return new EventContext(self::DRY_RUN_CONTEXT_PREFIX . $correlationId->value);
    }
}
