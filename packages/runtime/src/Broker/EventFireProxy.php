<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\EventFireResult;

final readonly class EventFireProxy
{
    public function __construct(
        private HaSession $session,
        private HaCallSlots $slots,
        private ServiceCallPolicy $policy,
        private LoggerInterface $logger,
    ) {}

    public function forward(WorkerHandle $handle, EventFireRequest $request): void
    {
        $this->slots->runBounded($handle, $request, fn(): EventFireResult => $this->fireOnHomeAssistant($request));
    }

    /** @throws EventFireException */
    private function fireOnHomeAssistant(EventFireRequest $request): EventFireResult
    {
        if ($this->policy->dryRun) {
            $this->logger->info('Event not fired: service_calls.dry_run is on', [
                'event_type' => $request->eventType,
                'data' => $request->data,
            ]);

            return new EventFireResult($request->correlationId, $this->policy->createDryRunContext($request->correlationId));
        }

        if (!$this->session->isConnected()) {
            throw $request->createUnreachableFailure('Home Assistant is disconnected');
        }

        return new EventFireResult($request->correlationId, $this->session->fireEvent(new EventPayload($request->eventType, $request->data)));
    }
}
