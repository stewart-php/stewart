<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerRestartOutcome;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\RestartWorkerRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;

/** @implements ControlRequestHandler<RestartWorkerRequest> */
final readonly class RestartWorkerRequestHandler implements ControlRequestHandler
{
    public function __construct(
        private WorkerSlotCollection $workerSlots,
        private WorkerPool $pool,
    ) {}

    public function handledMessageClass(): string
    {
        return RestartWorkerRequest::class;
    }

    /** @param RestartWorkerRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $slot = $this->workerSlots->requireSlotById($request->workerId);
        $outcome = $this->pool->restartWorker($slot);

        return new CommandResult($outcome === WorkerRestartOutcome::Begun, $outcome->describeForWorker($slot->workerId), null);
    }
}
