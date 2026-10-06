<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin\Response;

use Stewart\Runtime\Control\Protocol\Status\AppPauseStatus;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Lifecycle\AppState;

final readonly class AdminAppView
{
    public function __construct(
        public string $id,
        public ?AppState $state,
        public bool $paused,
        public ?AppPauseStatus $pause,
        public ?AppPauseStatus $configPauseOverride,
    ) {}

    public static function fromAppStatus(AppStatus $status): self
    {
        return new self($status->id, $status->state, $status->pause !== null, $status->pause, $status->configPauseOverride);
    }
}
