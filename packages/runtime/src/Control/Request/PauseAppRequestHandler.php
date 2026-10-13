<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @implements ControlRequestHandler<PauseAppRequest> */
final readonly class PauseAppRequestHandler implements ControlRequestHandler
{
    public function __construct(private RuntimeAdmin $admin) {}

    public function handledMessageClass(): string
    {
        return PauseAppRequest::class;
    }

    /** @param PauseAppRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        return $this->admin->pauseApp(new AppId($request->appId), AppPauseSource::Control);
    }
}
