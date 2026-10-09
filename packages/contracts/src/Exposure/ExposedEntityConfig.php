<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

abstract readonly class ExposedEntityConfig
{
    private const string ICON_PATTERN = '/\Amdi:[a-z0-9-]+\z/';

    /** @throws ExposureException */
    public function __construct(
        public ?string $name,
        public ?string $icon,
        public ?EntityCategory $entityCategory,
        public bool $enabledByDefault,
    ) {
        if ($name === '') {
            throw ExposureException::configInvalid('The entity name must not be empty; leave it null to use the device name.');
        }

        if ($icon !== null && preg_match(self::ICON_PATTERN, $icon) !== 1) {
            throw ExposureException::configInvalid(\sprintf('The icon "%s" must look like mdi:thermometer.', $icon));
        }
    }

    abstract public function getPlatform(): ExposedPlatform;
}
