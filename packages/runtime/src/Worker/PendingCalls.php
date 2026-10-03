<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\WorkerId;

final class PendingCalls
{
    /** @var array<string, PendingCall> */
    private array $pending = [];

    private int $counter = 0;

    public function __construct(private readonly WorkerId $workerId) {}

    public function open(string $domain, string $service): PendingCall
    {
        $call = new PendingCall(CorrelationId::fromString($this->workerId->value . ':' . $this->counter++), $domain, $service);
        $this->pending[$call->correlationId->value] = $call;

        return $call;
    }

    public function resolve(CorrelationId $correlationId, ServiceResponse $response): void
    {
        $this->take($correlationId)?->complete($response);
    }

    public function reject(ServiceCallFailed $error): void
    {
        $call = $this->take($error->correlationId);

        if ($call !== null) {
            $call->fail($error->toException());
        }
    }

    public function forget(CorrelationId $correlationId): void
    {
        unset($this->pending[$correlationId->value]);
    }

    public function failAll(string $detail): int
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $call) {
            $call->fail(ServiceCallException::unreachable($call->domain, $call->service, $detail));
        }

        return \count($pending);
    }

    private function take(CorrelationId $correlationId): ?PendingCall
    {
        $call = $this->pending[$correlationId->value] ?? null;
        unset($this->pending[$correlationId->value]);

        return $call;
    }
}
