<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\Exception\ExposureException;

final readonly class DateConfig extends ExposedEntityConfig
{
    /** @throws ExposureException */
    public function __construct(
        ?string $name = null,
        ?string $icon = null,
        ?EntityCategory $entityCategory = null,
        bool $enabledByDefault = true,
    ) {
        parent::__construct($name, $icon, $entityCategory, $enabledByDefault);
    }

    public function getPlatform(): ExposedPlatform
    {
        return ExposedPlatform::Date;
    }
}
