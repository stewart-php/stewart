<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Broker\Reload\ReloadTrigger;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ReloadRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResetAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\RestartWorkerRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\SnapshotSource;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final readonly class ControlRequestDispatcher
{
    public function __construct(
        private SnapshotSource $snapshots,
        private RuntimeAdmin $admin,
    ) {}

    /** @throws StewartException */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        return match (true) {
            $request instanceof SnapshotRequest => new SnapshotFrame($this->snapshots->takeSnapshot()),
            $request instanceof PauseAppRequest => $this->admin->pauseApp(new AppId($request->appId), AppPauseSource::Control),
            $request instanceof ResumeAppRequest => $this->admin->resumeApp(new AppId($request->appId), AppPauseSource::Control),
            $request instanceof ResetAppRequest => $this->admin->resetApp(new AppId($request->appId), AppPauseSource::Control),
            $request instanceof RestartWorkerRequest => $this->admin->restartWorker($request->workerId),
            $request instanceof ReloadRequest => $this->admin->reload(ReloadTrigger::Control),
            default => throw ControlException::createForReason(ControlError::RequestUnexpected, frameClass: $request::class),
        };
    }
}
