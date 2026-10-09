<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'exposed_entity_commanded')]
final readonly class ExposedEntityCommanded implements BrokerMessage
{
    public function __construct(
        public string $commandId,
        public ResourceScope $scope,
        public ExposedEntityKey $key,
        public ExposedCommand $command,
    ) {}

    public function getOutboxDelivery(): OutboxDelivery
    {
        return OutboxDelivery::Plain;
    }
}
