<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposureConfig;

final readonly class RecordedExposure
{
    public function __construct(
        public ExposedEntityKey $key,
        public ExposureConfig $config,
        public ?DeviceInfo $device,
    ) {}
}
