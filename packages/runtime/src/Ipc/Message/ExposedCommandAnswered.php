<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

#[IpcMessage(tag: 'exposed_command_answered')]
final readonly class ExposedCommandAnswered implements WorkerMessage
{
    public function __construct(
        public string $commandId,
        public bool $accepted,
        public ?string $rejection = null,
    ) {}
}
