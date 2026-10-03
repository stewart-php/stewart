<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Worker\WorkerShutdown;

/** @implements BrokerMessageHandler<Shutdown> */
final readonly class ShutdownHandler implements BrokerMessageHandler
{
    public function __construct(private WorkerShutdown $shutdown) {}

    public function handledMessageClass(): string
    {
        return Shutdown::class;
    }

    /** @param Shutdown $message */
    public function handle(BrokerMessage $message): void
    {
        $this->shutdown->stop($message->reason, $message->grace);
    }
}
