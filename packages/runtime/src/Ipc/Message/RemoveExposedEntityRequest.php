<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

#[IpcMessage(tag: 'remove_exposed_entity_request')]
final readonly class RemoveExposedEntityRequest implements WorkerMessage, HaCallRequest
{
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public ExposedEntityKey $key,
    ) {}

    public function createUnreachableFailure(string $detail, ?Throwable $previous = null): ExposureException
    {
        return ExposureException::unreachable($this->key, $detail, $previous);
    }
}
