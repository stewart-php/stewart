<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\RestartWorkerRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;

/** @implements ControlRequestHandler<RestartWorkerRequest> */
final readonly class RestartWorkerRequestHandler implements ControlRequestHandler
{
    public function __construct(private RuntimeAdmin $admin) {}

    public function handledMessageClass(): string
    {
        return RestartWorkerRequest::class;
    }

    /** @param RestartWorkerRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        return $this->admin->restartWorker($request->workerId);
    }
}
