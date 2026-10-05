<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Dispatch\SubscriptionListener;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Message\SubscribeTrigger;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Ipc\Transport;

final readonly class BrokerSubscriptions implements SubscriptionListener
{
    public function __construct(
        private Transport $transport,
        private LoggerInterface $logger,
    ) {}

    public function subscriptionRegistered(RegisteredSubscription $subscription): void
    {
        if (!$subscription->kind->isBrokerRouted()) {
            return;
        }

        if ($subscription->trigger !== null) {
            $this->transport->send(new SubscribeTrigger($subscription->id, $subscription->scope, $subscription->trigger));

            return;
        }

        $this->transport->send(new Subscribe(
            subscriptionId: $subscription->id,
            scope: $subscription->scope,
            kind: $subscription->kind,
            selector: $subscription->selector,
        ));
    }

    public function subscriptionCancelled(RegisteredSubscription $subscription): void
    {
        if (!$subscription->kind->isBrokerRouted()) {
            return;
        }

        try {
            $this->transport->send(new Unsubscribe($subscription->id));
        } catch (TransportException $e) {
            $this->logger->debug('Could not unsubscribe; the broker channel is closed', ['exception' => $e, 'subscription' => $subscription->id->value]);
        }
    }
}
