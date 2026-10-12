<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

#[IpcMessage(tag: 'service_call_request')]
final readonly class ServiceCallRequest implements WorkerMessage, RefusableHaCallRequest
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public string $domain,
        public string $service,
        public array $data,
        public ?ServiceTarget $target,
        public bool $returnResponse,
    ) {}

    public function createUnreachableFailure(string $detail, ?Throwable $previous = null): ServiceCallException
    {
        return ServiceCallException::unreachable($this->domain, $this->service, $detail, $previous);
    }

    public function createOverloadedFailure(string $detail): ServiceCallException
    {
        return ServiceCallException::overloaded($this->domain, $this->service, $detail);
    }
}
