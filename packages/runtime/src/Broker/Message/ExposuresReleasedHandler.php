<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\ExposuresReleased;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<ExposuresReleased> */
final readonly class ExposuresReleasedHandler implements WorkerMessageHandler
{
    public function __construct(private HaSession $session) {}

    public function handledMessageClass(): string
    {
        return ExposuresReleased::class;
    }

    /** @param ExposuresReleased $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        if ($message->scope->appId !== null) {
            $this->session->orphanExposuresOfApp($message->scope->appId);
        }
    }
}
