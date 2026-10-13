<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Action;

use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Wire\ListOf;

final readonly class ActionField
{
    private const string NAME_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';

    /**
     * @param list<string> $options
     * @throws ExposureException
     */
    public function __construct(
        public string $name,
        public ActionFieldType $type = ActionFieldType::Text,
        public bool $required = false,
        public ?string $description = null,
        #[ListOf('string')]
        public array $options = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw ExposureException::configInvalid(\sprintf('The action field name "%s" must be lower snake case.', $name));
        }

        if (($type === ActionFieldType::Select) !== ($options !== [])) {
            throw ExposureException::configInvalid(\sprintf('The action field "%s" takes options exactly when its type is select.', $name));
        }

        if (\in_array('', $options, true) || \count(array_unique($options)) !== \count($options)) {
            throw ExposureException::configInvalid(\sprintf('The options of action field "%s" must be distinct and not empty.', $name));
        }
    }
}
