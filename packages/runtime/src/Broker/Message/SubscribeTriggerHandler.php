<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\SubscribeTrigger;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Model\SubscriptionKind;

/** @implements WorkerMessageHandler<SubscribeTrigger> */
final readonly class SubscribeTriggerHandler implements WorkerMessageHandler
{
    public function __construct(
        private SubscriptionRegistry $registry,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return SubscribeTrigger::class;
    }

    /** @param SubscribeTrigger $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->registry->add(new BrokerSubscription(
            subscriptionId: $message->subscriptionId,
            workerId: $handle->id,
            scope: $message->scope,
            kind: SubscriptionKind::Trigger,
            selector: Selector::exact($message->trigger->getSharingKey()),
            trigger: $message->trigger,
        ));

        $handle->send(new SubscriptionAck($message->subscriptionId, true, null));

        $this->logger->debug('Trigger subscription registered', [
            'app' => $message->scope->wireValue(),
            'platforms' => $message->trigger->listPlatforms(),
        ]);
    }
}
