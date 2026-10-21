<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

use LogicException;
use Stewart\Runtime\Http\Admin\AdminAction;
use Stewart\Runtime\Http\Admin\AdminRoute;

final readonly class AdminApiPage implements ReferencePage
{
    public function getFileName(): string
    {
        return 'reference/admin-api.md';
    }

    public function renderMarkdown(): string
    {
        $rows = array_map(
            fn(AdminAction $action): array => [
                implode(', ', $action->listAllowedMethods()),
                MarkdownTable::formatCode($this->getVerifiedPath($action)),
                $this->describeAction($action),
            ],
            AdminAction::cases(),
        );

        return new MarkdownPage(
            'Admin API reference',
            'Every request the admin HTTP port answers.',
            5,
            implode("\n", [
                'The admin port is off by default. Every request needs `Authorization: Bearer <token>` with',
                '`http.admin.token`. [Status and control](../guides/status.md#admin-api) shows how to turn it on, and the answers',
                'and status codes.',
                '',
                MarkdownTable::formatTable(['Method', 'Path', 'Does'], $rows),
            ]),
        )->renderMarkdown();
    }

    private function getVerifiedPath(AdminAction $action): string
    {
        $path = match ($action) {
            AdminAction::ListApps => '/api/apps',
            AdminAction::ShowApp => '/api/apps/{id}',
            AdminAction::PauseApp => '/api/apps/{id}/pause',
            AdminAction::ResumeApp => '/api/apps/{id}/resume',
            AdminAction::ResetApp => '/api/apps/{id}/reset',
            AdminAction::RestartWorker => '/api/workers/{id}/restart',
            AdminAction::Reload => '/api/reload',
            AdminAction::ShowMetrics => '/metrics',
        };

        if (AdminRoute::fromPath(str_replace('{id}', 'sample', $path))?->action !== $action) {
            throw new LogicException(\sprintf('%s no longer routes to %s; update %s.', $path, $action->name, self::class));
        }

        return $path;
    }

    private function describeAction(AdminAction $action): string
    {
        return match ($action) {
            AdminAction::ListApps => 'Lists every app with its state and pause',
            AdminAction::ShowApp => 'Shows one app',
            AdminAction::PauseApp => 'Pauses an app, like `stewart app:pause`',
            AdminAction::ResumeApp => 'Resumes an app, like `stewart app:resume`',
            AdminAction::ResetApp => 'Forgets a stored pause or resume, like `stewart app:reset`',
            AdminAction::RestartWorker => 'Restarts a worker, like `stewart worker:restart`',
            AdminAction::Reload => 'Checks the project, then restarts every worker on the current code, like `stewart reload`',
            AdminAction::ShowMetrics => 'Prometheus metrics; see the [metrics reference](metrics.md)',
        };
    }
}
