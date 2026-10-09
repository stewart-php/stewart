<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

final readonly class TextConfig extends ExposedEntityConfig
{
    public const int MAX_LENGTH = 255;

    /** @throws ExposureException */
    public function __construct(
        public int $min = 0,
        public int $max = self::MAX_LENGTH,
        public ?string $pattern = null,
        public TextMode $mode = TextMode::Text,
        ?string $name = null,
        ?string $icon = null,
        ?EntityCategory $entityCategory = null,
        bool $enabledByDefault = true,
    ) {
        parent::__construct($name, $icon, $entityCategory, $enabledByDefault);

        if ($min < 0 || $min > $max || $max > self::MAX_LENGTH) {
            throw ExposureException::configInvalid(\sprintf('The text length limits must satisfy 0 <= min <= max <= %d.', self::MAX_LENGTH));
        }

        if ($pattern === '') {
            throw ExposureException::configInvalid('The text pattern must not be empty; leave it null for none.');
        }
    }

    public function getPlatform(): ExposedPlatform
    {
        return ExposedPlatform::Text;
    }
}
