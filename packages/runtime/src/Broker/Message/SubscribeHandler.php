<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<Subscribe> */
final readonly class SubscribeHandler implements WorkerMessageHandler
{
    public function __construct(
        private SubscriptionRegistry $registry,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return Subscribe::class;
    }

    /** @param Subscribe $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        if (!$message->kind->isBrokerRouted()) {
            $this->refuseSubscription($handle, $message, \sprintf('%s subscriptions are worker-local and must not be announced.', $message->kind->value));

            return;
        }

        if (!$message->kind->acceptsSelector($message->selector)) {
            $this->refuseSubscription($handle, $message, \sprintf('%s subscriptions do not accept %s selectors.', $message->kind->value, $message->selector->getKind()->value));

            return;
        }

        $this->registry->add(new BrokerSubscription(
            subscriptionId: $message->subscriptionId,
            workerId: $handle->id,
            scope: $message->scope,
            kind: $message->kind,
            selector: $message->selector,
        ));

        $handle->send(new SubscriptionAck($message->subscriptionId, true, null));

        $this->logger->debug('Subscription registered', [
            'app' => $message->scope->wireValue(),
            'kind' => $message->kind->value,
            'selector' => $message->selector->toCanonicalKey(),
        ]);
    }

    private function refuseSubscription(WorkerHandle $handle, Subscribe $message, string $reason): void
    {
        $handle->send(new SubscriptionAck($message->subscriptionId, false, $reason));
    }
}
