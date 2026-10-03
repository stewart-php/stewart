<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Connection\ConnectionLost;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Worker\ConnectionStatus;

/** @implements BrokerMessageHandler<HaConnectionLost> */
final readonly class HaConnectionLostHandler implements BrokerMessageHandler
{
    public function __construct(
        private ConnectionStatus $connection,
        private LocalDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return HaConnectionLost::class;
    }

    /** @param HaConnectionLost $message */
    public function handle(BrokerMessage $message): void
    {
        if (!$this->connection->markLost()) {
            return;
        }

        $this->logger->warning('Lost Home Assistant; state reads are stale until it is back', ['reason' => $message->reason]);
        $this->dispatcher->dispatchConnection(new ConnectionLost($message->lostAt, $message->reason));
    }
}
