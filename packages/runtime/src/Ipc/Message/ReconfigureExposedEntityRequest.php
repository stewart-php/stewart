<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'reconfigure_exposed_entity_request')]
final readonly class ReconfigureExposedEntityRequest implements WorkerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public ExposedEntityKey $key,
        public ExposedEntityConfig $config,
    ) {}
}
