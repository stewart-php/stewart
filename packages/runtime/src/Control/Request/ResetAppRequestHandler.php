<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\ResetAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @implements ControlRequestHandler<ResetAppRequest> */
final readonly class ResetAppRequestHandler implements ControlRequestHandler
{
    public function __construct(private RuntimeAdmin $admin) {}

    public function handledMessageClass(): string
    {
        return ResetAppRequest::class;
    }

    /** @param ResetAppRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        return $this->admin->resetApp(new AppId($request->appId), AppPauseSource::Control);
    }
}
