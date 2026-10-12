<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Closure;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Model\CorrelationId;

final class PendingRequests
{
    /** @var array<string, PendingRequest<object>> */
    private array $pending = [];

    public function __construct(private readonly CorrelationIdSequence $correlationIds) {}

    /**
     * @template TResult of object
     *
     * @param class-string<TResult> $resultClass
     * @param Closure(string): StewartException<ExceptionReason> $unreachable
     * @return PendingRequest<TResult>
     */
    public function open(string $resultClass, Closure $unreachable): PendingRequest
    {
        $request = new PendingRequest($this->correlationIds->issueNext(), $resultClass, $unreachable);
        $this->pending[$request->correlationId->value] = $request;

        return $request;
    }

    public function resolve(CorrelationId $correlationId, object $result): void
    {
        $this->take($correlationId)?->complete($result);
    }

    public function rejectFromWire(RequestFailed $failure): void
    {
        $this->take($failure->correlationId)?->failFromWire($failure);
    }

    public function forget(CorrelationId $correlationId): void
    {
        unset($this->pending[$correlationId->value]);
    }

    public function failAll(string $detail): int
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $request) {
            $request->failAsUnreachable($detail);
        }

        return \count($pending);
    }

    /** @return PendingRequest<object>|null */
    private function take(CorrelationId $correlationId): ?PendingRequest
    {
        $request = $this->pending[$correlationId->value] ?? null;
        unset($this->pending[$correlationId->value]);

        return $request;
    }
}
