<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\App\AppSelection;

final readonly class DaemonLaunchOptions
{
    public function __construct(
        public ?string $configFile = null,
        public AppSelection $selection = new AppSelection(),
        public ?string $logLevel = null,
        public bool $watch = false,
        public bool $dryRun = false,
    ) {}

    /** @return array<string, mixed> */
    public function listConfigOverrides(): array
    {
        $overrides = $this->logLevel === null ? [] : ['log_level' => $this->logLevel];

        return $this->dryRun ? [...$overrides, 'ha_calls' => ['dry_run' => true]] : $overrides;
    }
}
