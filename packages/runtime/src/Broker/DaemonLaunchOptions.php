<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

final readonly class DaemonLaunchOptions
{
    public function __construct(public ?string $configFile = null) {}
}
