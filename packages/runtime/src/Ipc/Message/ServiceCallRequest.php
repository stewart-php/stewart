<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'service_call_request')]
final readonly class ServiceCallRequest implements WorkerMessage
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
}
