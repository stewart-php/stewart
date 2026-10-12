<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Client\HaClient;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Message\HistoryResult;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Ipc\Wire\HistoricalStatesFragment;

/** @implements WorkerMessageHandler<HistoryRequest> */
final readonly class HistoryRequestHandler implements WorkerMessageHandler
{
    public function __construct(
        private HaSession $session,
        private HaClient $client,
        private HaCallSlots $slots,
    ) {}

    public function handledMessageClass(): string
    {
        return HistoryRequest::class;
    }

    /** @param HistoryRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->slots->runBounded($handle, $message, fn(): HistoryResult => $this->fetchFromHomeAssistant($message), metered: false);
    }

    /** @throws HistoryException */
    private function fetchFromHomeAssistant(HistoryRequest $request): HistoryResult
    {
        if (!$this->session->isConnected()) {
            throw $request->createUnreachableFailure('Home Assistant is disconnected');
        }

        $history = $this->client->fetchHistory($request->entityId, $request->window, $request->detail);

        return new HistoryResult($request->correlationId, HistoricalStatesFragment::fromCollection($history->states));
    }
}
