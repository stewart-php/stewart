<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Broker\AppPauseOutcomeMessages;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\ResetAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @implements ControlRequestHandler<ResetAppRequest> */
final readonly class ResetAppRequestHandler implements ControlRequestHandler
{
    public function __construct(
        private AppPauseService $pauses,
        private AppPauseOutcomeMessages $messages,
    ) {}

    public function handledMessageClass(): string
    {
        return ResetAppRequest::class;
    }

    /** @param ResetAppRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $appId = new AppId($request->appId);
        $outcome = $this->pauses->resetApp($appId, AppPauseSource::Control);

        return new CommandResult($outcome->overrideRemoved, $this->messages->describeResetOutcome($appId, $outcome), $this->messages->findResetWarning($outcome));
    }
}
