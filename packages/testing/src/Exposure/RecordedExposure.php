<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;

final readonly class RecordedExposure
{
    public function __construct(
        public ExposedEntityKey $key,
        public ExposedEntityConfig $config,
        public ?DeviceInfo $device,
    ) {}
}
