<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

final readonly class AdminRoute
{
    private const string RELOAD_PATH = '/api/reload';

    private const string APPS_PATH = '~\A/api/apps(?:/([^/]+)(?:/(pause|resume|reset))?)?\z~';

    private const string WORKER_RESTART_PATH = '~\A/api/workers/([^/]+)/restart\z~';

    public function __construct(
        public AdminAction $action,
        public ?string $resourceId,
    ) {}

    public static function fromPath(string $path): ?self
    {
        if ($path === self::RELOAD_PATH) {
            return new self(AdminAction::Reload, null);
        }

        if (preg_match(self::WORKER_RESTART_PATH, $path, $matches) === 1) {
            return new self(AdminAction::RestartWorker, rawurldecode($matches[1]));
        }

        if (preg_match(self::APPS_PATH, $path, $matches) !== 1) {
            return null;
        }

        $appId = $matches[1] ?? '';

        if ($appId === '') {
            return new self(AdminAction::ListApps, null);
        }

        return new self(AdminAction::tryFrom($matches[2] ?? '') ?? AdminAction::ShowApp, rawurldecode($appId));
    }

    public function allowsMethod(string $method): bool
    {
        return \in_array($method, $this->action->listAllowedMethods(), true);
    }
}
