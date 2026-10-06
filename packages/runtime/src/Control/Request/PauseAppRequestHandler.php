<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @implements ControlRequestHandler<PauseAppRequest> */
final readonly class PauseAppRequestHandler implements ControlRequestHandler
{
    public function __construct(private AppPauseService $pauses) {}

    public function handledMessageClass(): string
    {
        return PauseAppRequest::class;
    }

    /** @param PauseAppRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $appId = new AppId($request->appId);
        $changed = $this->pauses->pauseApp($appId, AppPauseSource::Control);

        return new CommandResult($changed, \sprintf($changed ? 'App %s paused.' : 'App %s was already paused.', $appId));
    }
}
