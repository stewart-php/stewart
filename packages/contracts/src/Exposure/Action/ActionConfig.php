<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Action;

use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\Exposure\ExposureConfig;
use Stewart\Contracts\Wire\ListOf;

final readonly class ActionConfig implements ExposureConfig
{
    /**
     * @param list<ActionField> $fields
     * @throws ExposureException
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        #[ListOf(ActionField::class)]
        public array $fields = [],
    ) {
        if ($name === '') {
            throw ExposureException::configInvalid('The action name must not be empty; leave it null to use the key.');
        }

        $names = array_map(static fn(ActionField $field): string => $field->name, $fields);

        if (\count(array_unique($names)) !== \count($names)) {
            throw ExposureException::configInvalid('The action field names must be distinct.');
        }
    }

    public function getPlatform(): ExposedPlatform
    {
        return ExposedPlatform::Action;
    }
}
