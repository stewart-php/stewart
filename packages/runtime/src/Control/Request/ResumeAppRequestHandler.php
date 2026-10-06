<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @extends AppPauseChangeHandler<ResumeAppRequest> */
final readonly class ResumeAppRequestHandler extends AppPauseChangeHandler
{
    public function handledMessageClass(): string
    {
        return ResumeAppRequest::class;
    }

    protected function changePause(AppId $appId): bool
    {
        return $this->pauses->resumeApp($appId, AppPauseSource::Control);
    }

    protected function describeOutcome(AppId $appId, bool $changed): string
    {
        return \sprintf($changed ? 'App %s resumed.' : 'App %s was not paused.', $appId);
    }
}
