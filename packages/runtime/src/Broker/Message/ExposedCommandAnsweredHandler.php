<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\Exposure\ExposureCommandRouter;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\ExposedCommandAnswered;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<ExposedCommandAnswered> */
final readonly class ExposedCommandAnsweredHandler implements WorkerMessageHandler
{
    public function __construct(private ExposureCommandRouter $router) {}

    public function handledMessageClass(): string
    {
        return ExposedCommandAnswered::class;
    }

    /** @param ExposedCommandAnswered $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->router->completeCommand($handle, $message);
    }
}
