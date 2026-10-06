<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @extends AppPauseChangeHandler<PauseAppRequest> */
final readonly class PauseAppRequestHandler extends AppPauseChangeHandler
{
    public function handledMessageClass(): string
    {
        return PauseAppRequest::class;
    }

    protected function changePause(AppId $appId): bool
    {
        return $this->pauses->pauseApp($appId, AppPauseSource::Control);
    }

    protected function describeOutcome(AppId $appId, bool $changed): string
    {
        return \sprintf($changed ? 'App %s paused.' : 'App %s was already paused.', $appId);
    }
}
