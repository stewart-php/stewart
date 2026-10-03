<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\EventFired;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Ipc\Message\TopicMessage;
use Stewart\Runtime\Ipc\Wire\EncodedStateChange;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\SubscriptionKind;

final readonly class EventRouter
{
    public function __construct(
        private WorkerSlotRegistry $slots,
        private SubscriptionRegistry $registry,
        private LoggerInterface $logger,
    ) {}

    public function broadcastStateChange(StateChange $change): void
    {
        $encoded = new EncodedStateChange($change);

        foreach ($this->slots->listLiveHandles() as $handle) {
            $handle->sendStateChange($encoded);
        }
    }

    public function routeEvent(HaEvent $event): void
    {
        $routes = $this->registry->findRoutes(SubscriptionKind::Event, $event->type);

        $this->deliverToMatched(
            $routes,
            static fn(SubscriptionIdCollection $deliverTo): BrokerMessage => new EventFired($event, $deliverTo->listValues()),
        );

        if ($routes->isEmpty()) {
            return;
        }

        $this->logger->debug('Home Assistant event routed', [
            'event_type' => $event->type,
            'workers' => $routes->listWorkerIds()->toInts(),
        ]);
    }

    public function routePublish(Publish $message): void
    {
        $event = new TopicEvent(
            topic: $message->topic,
            payload: $message->payload,
            publisher: $message->publisherScope->appId,
            publishedAt: $message->publishedAt,
        );

        $routes = $this->registry->findRoutes(SubscriptionKind::Topic, $message->topic);

        $this->deliverToMatched(
            $routes,
            static fn(SubscriptionIdCollection $deliverTo): BrokerMessage => new TopicMessage($event, $deliverTo->listValues()),
        );

        $this->logger->debug('Topic published', [
            'topic' => $message->topic,
            'from' => $message->publisherScope->wireValue(),
            'workers' => $routes->listWorkerIds()->toInts(),
        ]);
    }

    /** @param Closure(SubscriptionIdCollection): BrokerMessage $message */
    private function deliverToMatched(SubscriptionRoutes $routes, Closure $message): void
    {
        foreach ($routes->listWorkerIds() as $workerId) {
            $this->slots->findHandleForWorker($workerId)?->send($message($routes->listSubscriptionIdsForWorker($workerId)));
        }
    }
}
