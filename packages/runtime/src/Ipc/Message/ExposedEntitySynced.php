<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'exposed_entity_synced')]
final readonly class ExposedEntitySynced implements BrokerMessage
{
    public function __construct(
        public ResourceScope $scope,
        public ExposedEntityKey $key,
        public ExposedEntitySnapshot $snapshot,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
