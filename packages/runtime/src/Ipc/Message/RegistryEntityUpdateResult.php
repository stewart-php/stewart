<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Runtime\Model\CorrelationId;

#[IpcMessage(tag: 'registry_entity_update_result')]
final readonly class RegistryEntityUpdateResult implements CorrelatedReply
{
    public function __construct(
        public CorrelationId $correlationId,
        public RegisteredEntity $entity,
    ) {}

    public function getAnswer(): RegisteredEntity
    {
        return $this->entity;
    }

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
