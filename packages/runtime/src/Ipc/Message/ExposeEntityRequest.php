<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'expose_entity_request')]
final readonly class ExposeEntityRequest implements WorkerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public ExposedEntityKey $key,
        public ExposedEntityConfig $config,
        public ?DeviceInfo $device,
        public ExposedStateChange $change,
    ) {}
}
