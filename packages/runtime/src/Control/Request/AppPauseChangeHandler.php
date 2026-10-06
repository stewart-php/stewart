<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
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
    public function __construct(protected AppPauseService $pauses) {}

    /** @param T $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $appId = new AppId($request->appId);
        $changed = $this->changePause($appId);

        return new CommandResult($changed, $this->describeOutcome($appId, $changed));
    }

    /** @throws StewartException */
    abstract protected function changePause(AppId $appId): bool;

    abstract protected function describeOutcome(AppId $appId, bool $changed): string;
}
