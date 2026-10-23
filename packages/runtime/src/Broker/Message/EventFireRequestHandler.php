<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Client\HaClient;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Config\HaCallPolicy;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\EventFireResult;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Logging\LogOrigin;
use Stewart\Support\Wire\EventPayload;

/** @implements WorkerMessageHandler<EventFireRequest> */
final readonly class EventFireRequestHandler implements WorkerMessageHandler
{
    public function __construct(
        private HaSession $session,
        private HaClient $client,
        private HaCallSlots $slots,
        private HaCallPolicy $policy,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return EventFireRequest::class;
    }

    /** @param EventFireRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->slots->runBounded($handle, $message, fn(): EventFireResult => $this->fireOnHomeAssistant($message));
    }

    /** @throws EventFireException */
    private function fireOnHomeAssistant(EventFireRequest $request): EventFireResult
    {
        if ($this->policy->dryRun) {
            $this->logger->info('Event not fired: ha_calls.dry_run is on', [
                'event_type' => $request->eventType,
                'data' => $request->data,
                LogOrigin::CONTEXT_KEY => LogOrigin::forScope($request->scope),
            ]);

            return new EventFireResult($request->correlationId, $this->policy->createDryRunContext($request->correlationId));
        }

        if (!$this->session->isConnected()) {
            throw $request->createUnreachableFailure('Home Assistant is disconnected');
        }

        return new EventFireResult($request->correlationId, $this->client->fireEvent(new EventPayload($request->eventType, $request->data)));
    }
}
