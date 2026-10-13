<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Lifecycle\AppPauseSource;

/** @implements ControlRequestHandler<ResumeAppRequest> */
final readonly class ResumeAppRequestHandler implements ControlRequestHandler
{
    public function __construct(private RuntimeAdmin $admin) {}

    public function handledMessageClass(): string
    {
        return ResumeAppRequest::class;
    }

    /** @param ResumeAppRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        return $this->admin->resumeApp(new AppId($request->appId), AppPauseSource::Control);
    }
}
