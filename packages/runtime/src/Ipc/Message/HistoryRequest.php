<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'history_request')]
final readonly class HistoryRequest implements WorkerMessage
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public EntityId $entityId,
        public HistoryWindow $window,
        public bool $includeAttributes,
    ) {}
}
