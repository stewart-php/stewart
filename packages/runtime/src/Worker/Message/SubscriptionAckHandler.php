<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;

/** @implements BrokerMessageHandler<SubscriptionAck> */
final readonly class SubscriptionAckHandler implements BrokerMessageHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private LocalDispatcher $dispatcher,
    ) {}

    public function handledMessageClass(): string
    {
        return SubscriptionAck::class;
    }

    /** @param SubscriptionAck $message */
    public function handle(BrokerMessage $message): void
    {
        if ($message->accepted) {
            return;
        }

        $this->logger->error('Broker rejected a subscription', [
            'subscription' => $message->subscriptionId->value,
            'reason' => $message->reason ?? 'unknown',
        ]);

        $this->dispatcher->cancel($message->subscriptionId);
    }
}
