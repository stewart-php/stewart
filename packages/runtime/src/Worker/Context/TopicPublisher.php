<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Context;

use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Topic\TopicPayload;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\AppActivityCounters;

final readonly class TopicPublisher
{
    public function __construct(
        private Transport $transport,
        private Clock $clock,
        private AppActivityCounters $activityCounters,
    ) {}

    /** @throws TransportException */
    public function publish(ResourceScope $scope, string $topic, TopicPayload $payload): void
    {
        $this->transport->send(new Publish(
            topic: $topic,
            payload: $payload->value,
            publisherScope: $scope,
            publishedAt: $this->clock->getNow(),
        ));
        $this->activityCounters->findOrCreateActivityForScope($scope)->recordPublish();
    }
}
