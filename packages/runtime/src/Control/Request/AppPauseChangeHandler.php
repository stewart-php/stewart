<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\Broker\AppPauseOutcomeMessages;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;

/**
 * @template T of PauseAppRequest|ResumeAppRequest
 * @implements ControlRequestHandler<T>
 */
abstract readonly class AppPauseChangeHandler implements ControlRequestHandler
{
    public function __construct(
        protected AppPauseService $pauses,
        protected AppPauseOutcomeMessages $messages,
    ) {}

    /** @param T $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $appId = new AppId($request->appId);
        $outcome = $this->changePause($appId);

        return new CommandResult($outcome->changed, $this->describeOutcome($appId, $outcome), $outcome->persistence->findWarning());
    }

    /** @throws StewartException */
    abstract protected function changePause(AppId $appId): AppPauseOutcome;

    abstract protected function describeOutcome(AppId $appId, AppPauseOutcome $outcome): string;
}
