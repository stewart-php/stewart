<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

final readonly class DeviceInfo
{
    private const string IDENTIFIER_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    /** @throws ExposureException */
    public function __construct(
        public string $identifier,
        public string $name,
        public ?string $manufacturer = null,
        public ?string $model = null,
        public ?string $suggestedArea = null,
    ) {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw ExposureException::configInvalid(\sprintf('The device identifier "%s" must be lowercase letters, digits and "_", starting with a letter.', $identifier));
        }

        if ($name === '') {
            throw ExposureException::configInvalid('The device name must not be empty.');
        }
    }
}
