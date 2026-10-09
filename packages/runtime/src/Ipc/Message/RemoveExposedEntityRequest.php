<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'remove_exposed_entity_request')]
final readonly class RemoveExposedEntityRequest implements WorkerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public ExposedEntityKey $key,
    ) {}
}
