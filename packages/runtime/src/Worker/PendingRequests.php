<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Model\CorrelationId;
use Throwable;

final class PendingRequests
{
    /** @var array<string, PendingRequest<object>> */
    private array $pending = [];

    public function __construct(private readonly CorrelationIdSequence $correlationIds) {}

    /**
     * @template TResult of object
     * @param PendingRequestSubject<TResult> $subject
     * @return PendingRequest<TResult>
     */
    public function open(PendingRequestSubject $subject): PendingRequest
    {
        $request = new PendingRequest($this->correlationIds->issueNext(), $subject);
        $this->pending[$request->correlationId->value] = $request;

        return $request;
    }

    public function resolve(CorrelationId $correlationId, object $result): void
    {
        $this->take($correlationId)?->complete($result);
    }

    public function reject(CorrelationId $correlationId, Throwable $failure): void
    {
        $this->take($correlationId)?->fail($failure);
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
            $request->fail($request->subject->createUnreachableFailure($detail));
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
