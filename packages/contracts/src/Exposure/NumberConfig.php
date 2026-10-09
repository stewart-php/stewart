<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

final readonly class NumberConfig extends ExposedEntityConfig
{
    /** @throws ExposureException */
    public function __construct(
        public int|float $min,
        public int|float $max,
        public int|float $step = 1,
        public NumberMode $mode = NumberMode::Auto,
        public ?NumberDeviceClass $deviceClass = null,
        public ?string $unit = null,
        ?string $name = null,
        ?string $icon = null,
        ?EntityCategory $entityCategory = null,
        bool $enabledByDefault = true,
    ) {
        parent::__construct($name, $icon, $entityCategory, $enabledByDefault);

        if (!is_finite($min) || !is_finite($max) || !is_finite($step)) {
            throw ExposureException::configInvalid('The number min, max and step must be finite.');
        }

        if ($min > $max) {
            throw ExposureException::configInvalid('The number min must not be greater than its max.');
        }

        if ($step <= 0) {
            throw ExposureException::configInvalid('The number step must be greater than 0.');
        }

        if ($unit === '') {
            throw ExposureException::configInvalid('The number unit must not be empty; leave it null for none.');
        }
    }

    public function getPlatform(): ExposedPlatform
    {
        return ExposedPlatform::Number;
    }

    /** @throws ExposureException */
    public function formatState(int|float|null $value): int|float|null
    {
        return \is_float($value) && !is_finite($value) ? throw ExposureException::stateInvalid('A number state must be finite.') : $value;
    }
}
