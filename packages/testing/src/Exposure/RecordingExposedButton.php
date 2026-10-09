<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\ExposedButton;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;

final class RecordingExposedButton extends RecordingExposedEntity implements ExposedButton
{
    public function __construct(
        ExposedEntityKey $key,
        public readonly ButtonConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }
}
