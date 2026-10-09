<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\State\EventContext;

final readonly class ComponentEventDecoder
{
    private const string SESSION_REPLACED = 'session_replaced';

    private const string COMMAND = 'command';

    /** @param array<string, mixed> $event */
    public function decodeSessionEvent(array $event): ?ComponentSessionEvent
    {
        return match ($event['type'] ?? null) {
            self::SESSION_REPLACED => new SessionReplaced(),
            self::COMMAND => $this->decodeCommand($event),
            default => null,
        };
    }

    /** @param array<string, mixed> $event */
    private function decodeCommand(array $event): ?ComponentCommand
    {
        $commandId = $event['command_id'] ?? null;
        $appId = \is_string($event['app'] ?? null) ? AppId::tryFromString($event['app']) : null;
        $key = \is_string($event['key'] ?? null) ? ExposedEntityKey::tryFromString($event['key']) : null;
        $action = \is_string($event['action'] ?? null) ? ComponentCommandAction::tryFrom($event['action']) : null;
        $data = $event['data'] ?? null;
        $context = $event['context'] ?? null;

        if (!\is_string($commandId) || $appId === null || $key === null || $action === null || !\is_array($data) || !\is_array($context)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return new ComponentCommand($commandId, $appId, $key, $action, $data, EventContext::fromArray($context));
    }
}
