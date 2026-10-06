<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\Broker\AppPauseOutcomeMessages;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
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
        private AppCatalog $apps,
        private AppPauseOutcomeMessages $messages,
    ) {}

    public function listApps(): AdminAppList
    {
        return new AdminAppList($this->appStatuses->buildAppStatuses()
            ->filter($this->isLoadedAppStatus(...))
            ->mapToList(AdminAppView::fromAppStatus(...)));
    }

    /** @throws AppException */
    public function showApp(AppId $appId): AdminAppView
    {
        $this->pauses->assertAppLoaded($appId);
        $status = $this->appStatuses->buildAppStatuses()->findFirstWhere(static fn(AppStatus $status): bool => $status->id === $appId->value);

        return AdminAppView::fromAppStatus($status ?? throw AppException::unknown($appId));
    }

    /** @throws AppException */
    public function pauseApp(AppId $appId): AdminCommandResult
    {
        $outcome = $this->pauses->pauseApp($appId, AppPauseSource::Http);

        return new AdminCommandResult($outcome->changed, $this->messages->describePauseOutcome($appId, $outcome), $outcome->persistence->findWarning());
    }

    /** @throws AppException */
    public function resumeApp(AppId $appId): AdminCommandResult
    {
        $outcome = $this->pauses->resumeApp($appId, AppPauseSource::Http);

        return new AdminCommandResult($outcome->changed, $this->messages->describeResumeOutcome($appId, $outcome), $outcome->persistence->findWarning());
    }

    /** @throws AppException|StoreException */
    public function resetApp(AppId $appId): AdminCommandResult
    {
        $outcome = $this->pauses->resetApp($appId, AppPauseSource::Http);

        return new AdminCommandResult($outcome->overrideRemoved, $this->messages->describeResetOutcome($appId, $outcome), $this->messages->findResetWarning($outcome));
    }

    private function isLoadedAppStatus(AppStatus $status): bool
    {
        $appId = AppId::tryFromString($status->id);

        return $appId !== null && $this->apps->enabled->find($appId) !== null;
    }
}
