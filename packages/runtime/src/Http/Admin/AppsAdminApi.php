<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\Broker\AppPauseOutcomeMessages;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Http\Admin\Response\AdminAppList;
use Stewart\Runtime\Http\Admin\Response\AdminAppView;
use Stewart\Runtime\Http\Admin\Response\AdminCommandResult;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final readonly class AppsAdminApi
{
    public function __construct(
        private AppStatusBuilder $appStatuses,
        private AppPauseService $pauses,
        private AppPauseOutcomeMessages $messages,
    ) {}

    public function listApps(): AdminAppList
    {
        return new AdminAppList($this->appStatuses->buildHostedAppStatuses()->mapToList(AdminAppView::fromAppStatus(...)));
    }

    /** @throws AppException */
    public function showApp(AppId $appId): AdminAppView
    {
        $this->pauses->assertAppLoaded($appId);

        return AdminAppView::fromAppStatus($this->appStatuses->findAppStatus($appId) ?? throw AppException::unknown($appId));
    }

    /** @throws AppException */
    public function pauseApp(AppId $appId): AdminCommandResult
    {
        $outcome = $this->pauses->pauseApp($appId, AppPauseSource::Http);

        return new AdminCommandResult($outcome->changed, $this->messages->describePauseOutcome($appId, $outcome), $this->messages->findChangeWarning($outcome));
    }

    /** @throws AppException */
    public function resumeApp(AppId $appId): AdminCommandResult
    {
        $outcome = $this->pauses->resumeApp($appId, AppPauseSource::Http);

        return new AdminCommandResult($outcome->changed, $this->messages->describeResumeOutcome($appId, $outcome), $this->messages->findChangeWarning($outcome));
    }

    /** @throws AppException|StoreException */
    public function resetApp(AppId $appId): AdminCommandResult
    {
        $outcome = $this->pauses->resetApp($appId, AppPauseSource::Http);

        return new AdminCommandResult($outcome->overrideRemoved, $this->messages->describeResetOutcome($appId, $outcome), $this->messages->findResetWarning($outcome));
    }
}
