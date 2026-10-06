<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

final readonly class AdminRoute
{
    private const string METRICS_PATH = '/metrics';

    private const string APPS_PATH = '~\A/api/apps(?:/([^/]+)(?:/(pause|resume|reset))?)?\z~';

    public function __construct(
        public AdminAction $action,
        public ?string $appId,
    ) {}

    public static function fromPath(string $path): ?self
    {
        if ($path === self::METRICS_PATH) {
            return new self(AdminAction::ShowMetrics, null);
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
