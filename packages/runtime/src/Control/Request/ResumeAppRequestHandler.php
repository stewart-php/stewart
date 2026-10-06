<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @implements ControlRequestHandler<ResumeAppRequest> */
final readonly class ResumeAppRequestHandler implements ControlRequestHandler
{
    public function __construct(private AppPauseService $pauses) {}

    public function handledMessageClass(): string
    {
        return ResumeAppRequest::class;
    }

    /** @param ResumeAppRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $appId = new AppId($request->appId);
        $changed = $this->pauses->resumeApp($appId, AppPauseSource::Control);

        return new CommandResult($changed, \sprintf($changed ? 'App %s resumed.' : 'App %s was not paused.', $appId));
    }
}
