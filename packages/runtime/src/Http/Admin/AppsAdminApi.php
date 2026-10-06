<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Http\Admin\Response\AdminAppList;
use Stewart\Runtime\Http\Admin\Response\AdminAppView;

final readonly class AppsAdminApi
{
    public function __construct(
        private AppStatusBuilder $appStatuses,
        private AppPauseService $pauses,
        private AppCatalog $apps,
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

    private function isLoadedAppStatus(AppStatus $status): bool
    {
        $appId = AppId::tryFromString($status->id);

        return $appId !== null && $this->apps->enabled->find($appId) !== null;
    }
}
