<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

interface ExposureConfig
{
    public function getPlatform(): ExposedPlatform;
}
