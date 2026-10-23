<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Logging\LogOrigin;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Throwable;

final readonly class ServiceCallTracer
{
    public function __construct(
        private LoggerInterface $logger,
        private LogVerbosityPolicy $verbosity,
    ) {}

    public function traceCallSent(ServiceCallRequest $request): void
    {
        if (!$this->verbosity->isTracing($request->scope)) {
            return;
        }

        $details = $this->describeCall($request);
        $target = $request->target?->toArray() ?? [];

        if ($target !== []) {
            $details['target'] = $target;
        }

        if ($request->data !== []) {
            $details['data'] = $request->data;
        }

        $this->logger->debug('Service call sent', [...$details, LogOrigin::CONTEXT_KEY => LogOrigin::forScope($request->scope, traced: true)]);
    }

    public function traceCallAnswered(ServiceCallRequest $request, Duration $took, ?Throwable $failure = null): void
    {
        if (!$this->verbosity->isTracing($request->scope)) {
            return;
        }

        $outcome = match (true) {
            $failure === null => ServiceCallOutcome::Succeeded,
            $failure instanceof ActionCallException => ServiceCallOutcome::failedWith($failure->reason),
            default => ServiceCallOutcome::Unreachable,
        };

        $details = [...$this->describeCall($request), 'outcome' => $outcome->value, 'took' => (string) $took];

        if ($failure !== null) {
            $details['error'] = $failure->getMessage();
        }

        $this->logger->debug('Service call answered', [...$details, LogOrigin::CONTEXT_KEY => LogOrigin::forScope($request->scope, traced: true)]);
    }

    /** @return array<string, mixed> */
    private function describeCall(ServiceCallRequest $request): array
    {
        return ['service' => $request->domain . '.' . $request->service];
    }
}
