<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

final readonly class SelectConfig extends ExposedEntityConfig
{
    /**
     * @param list<string> $options
     * @throws ExposureException
     */
    public function __construct(
        public array $options,
        ?string $name = null,
        ?string $icon = null,
        ?EntityCategory $entityCategory = null,
        bool $enabledByDefault = true,
    ) {
        parent::__construct($name, $icon, $entityCategory, $enabledByDefault);

        if ($options === []) {
            throw ExposureException::configInvalid('A select needs at least one option.');
        }

        if (\in_array('', $options, true) || \count(array_unique($options)) !== \count($options)) {
            throw ExposureException::configInvalid('The select options must be distinct and not empty.');
        }
    }

    public function getPlatform(): ExposedPlatform
    {
        return ExposedPlatform::Select;
    }
}
