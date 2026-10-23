<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

#[IpcMessage(tag: 'action_call_request')]
final readonly class ActionCallRequest implements WorkerMessage, RefusableHaCallRequest
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public CorrelationId $correlationId,
        public ResourceScope $scope,
        public string $domain,
        public string $action,
        public array $data,
        public ?ActionTarget $target,
        public bool $returnResponse,
    ) {}

    public function createUnreachableFailure(string $detail, ?Throwable $previous = null): ActionCallException
    {
        return ActionCallException::unreachable($this->domain, $this->action, $detail, $previous);
    }

    public function createOverloadedFailure(string $detail): ActionCallException
    {
        return ActionCallException::overloaded($this->domain, $this->action, $detail);
    }
}
