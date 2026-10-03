<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Exception\ContainerException;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\MessageHandlerIndex;

final readonly class BrokerMessageDispatcher
{
    /** @var MessageHandlerIndex<BrokerMessageHandler<*>> */
    private MessageHandlerIndex $handlers;

    /**
     * @param iterable<BrokerMessageHandler<*>> $handlers
     *
     * @throws ContainerException
     */
    public function __construct(
        iterable $handlers,
        private LoggerInterface $logger,
    ) {
        $this->handlers = MessageHandlerIndex::fromHandlers($handlers);
    }

    public function dispatch(BrokerMessage $message): void
    {
        /** @var BrokerMessageHandler<BrokerMessage>|null $handler */
        $handler = $this->handlers->findHandlerFor($message);

        if ($handler === null) {
            $this->logger->warning('Ignoring an unknown broker message', ['message' => $message::class]);

            return;
        }

        $handler->handle($message);
    }
}
