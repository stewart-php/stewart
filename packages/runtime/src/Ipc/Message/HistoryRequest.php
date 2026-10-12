<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

#[IpcMessage(tag: 'history_request')]
final readonly class HistoryRequest implements WorkerMessage, RefusableHaCallRequest
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public EntityId $entityId,
        public HistoryWindow $window,
        public HistoryDetail $detail,
    ) {}

    public function createUnreachableFailure(string $detail, ?Throwable $previous = null): HistoryException
    {
        return HistoryException::unreachable($this->entityId, $detail, $previous);
    }

    public function createOverloadedFailure(string $detail): HistoryException
    {
        return HistoryException::createForReason(HistoryError::Overloaded, entityId: $this->entityId->value, detail: $detail);
    }
}
