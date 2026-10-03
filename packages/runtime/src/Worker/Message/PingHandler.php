<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Worker\PongBuilder;

/** @implements BrokerMessageHandler<Ping> */
final readonly class PingHandler implements BrokerMessageHandler
{
    public function __construct(
        private Transport $transport,
        private PongBuilder $pongBuilder,
    ) {}

    public function handledMessageClass(): string
    {
        return Ping::class;
    }

    /** @param Ping $message */
    public function handle(BrokerMessage $message): void
    {
        $this->transport->send($this->pongBuilder->buildPongFor($message));
    }
}
