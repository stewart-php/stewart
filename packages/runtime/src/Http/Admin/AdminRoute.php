<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

final readonly class AdminRoute
{
    private const string APPS_PATH = '~\A/api/apps(?:/([^/]+))?\z~';

    public function __construct(
        public AdminAction $action,
        public ?string $appId,
    ) {}

    public static function fromPath(string $path): ?self
    {
        if (preg_match(self::APPS_PATH, $path, $matches) !== 1) {
            return null;
        }

        $appId = $matches[1] ?? '';

        return $appId === '' ? new self(AdminAction::ListApps, null) : new self(AdminAction::ShowApp, rawurldecode($appId));
    }

    public function allowsMethod(string $method): bool
    {
        return \in_array($method, $this->action->listAllowedMethods(), true);
    }
}
