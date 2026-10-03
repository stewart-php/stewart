<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\Message\BrokerMessage;

/** @implements BrokerMessageHandler<Bootstrap> */
final readonly class BootstrapHandler implements BrokerMessageHandler
{
    public function __construct(private LoggerInterface $logger) {}

    public function handledMessageClass(): string
    {
        return Bootstrap::class;
    }

    /** @param Bootstrap $message */
    public function handle(BrokerMessage $message): void
    {
        $this->logger->warning('Ignoring a second Bootstrap');
    }
}
