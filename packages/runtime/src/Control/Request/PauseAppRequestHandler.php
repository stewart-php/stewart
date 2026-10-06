<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @extends AppPauseChangeHandler<PauseAppRequest> */
final readonly class PauseAppRequestHandler extends AppPauseChangeHandler
{
    public function handledMessageClass(): string
    {
        return PauseAppRequest::class;
    }

    protected function changePause(AppId $appId): AppPauseOutcome
    {
        return $this->pauses->pauseApp($appId, AppPauseSource::Control);
    }

    protected function describeOutcome(AppId $appId, AppPauseOutcome $outcome): string
    {
        return $this->messages->describePauseOutcome($appId, $outcome);
    }
}
