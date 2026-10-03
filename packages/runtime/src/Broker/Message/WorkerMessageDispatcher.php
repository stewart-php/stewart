<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Exception\ContainerException;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Ipc\MessageHandlerIndex;

final readonly class WorkerMessageDispatcher
{
    /** @var MessageHandlerIndex<WorkerMessageHandler<*>> */
    private MessageHandlerIndex $handlers;

    /**
     * @param iterable<WorkerMessageHandler<*>> $handlers
     *
     * @throws ContainerException
     */
    public function __construct(
        iterable $handlers,
        private LoggerInterface $logger,
    ) {
        $this->handlers = MessageHandlerIndex::fromHandlers($handlers);
    }

    public function dispatch(WorkerHandle $handle, WorkerMessage $message): void
    {
        /** @var WorkerMessageHandler<WorkerMessage>|null $handler */
        $handler = $this->handlers->findHandlerFor($message);

        if ($handler === null) {
            $this->logger->warning('Ignoring an unknown worker message', [
                'worker' => $handle->id->value,
                'message' => $message::class,
            ]);

            return;
        }

        $handler->handle($handle, $message);
    }
}
