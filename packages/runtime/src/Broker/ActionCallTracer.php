<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\ActionCallRequest;
use Stewart\Runtime\Logging\LogOrigin;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\HaCallOutcome;
use Throwable;

final readonly class ActionCallTracer
{
    public function __construct(
        private LoggerInterface $logger,
        private LogVerbosityPolicy $verbosity,
    ) {}

    public function traceCallSent(ActionCallRequest $request): void
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

        $this->logger->debug('Action call sent', [...$details, LogOrigin::CONTEXT_KEY => LogOrigin::forScope($request->scope, traced: true)]);
    }

    public function traceCallAnswered(ActionCallRequest $request, Duration $took, ?Throwable $failure = null): void
    {
        if (!$this->verbosity->isTracing($request->scope)) {
            return;
        }

        $outcome = match (true) {
            $failure === null => HaCallOutcome::Succeeded,
            $failure instanceof ActionCallException => HaCallOutcome::failedWith($failure->reason),
            default => HaCallOutcome::Unreachable,
        };

        $details = [...$this->describeCall($request), 'outcome' => $outcome->value, 'took' => (string) $took];

        if ($failure !== null) {
            $details['error'] = $failure->getMessage();
        }

        $this->logger->debug('Action call answered', [...$details, LogOrigin::CONTEXT_KEY => LogOrigin::forScope($request->scope, traced: true)]);
    }

    /** @return array<string, mixed> */
    private function describeCall(ActionCallRequest $request): array
    {
        return ['action' => $request->domain . '.' . $request->action];
    }
}
