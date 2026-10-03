<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Ipc\MessageHandler;

/**
 * @template T of WorkerMessage
 *
 * @extends MessageHandler<T>
 */
interface WorkerMessageHandler extends MessageHandler
{
    /** @param T $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void;
}
