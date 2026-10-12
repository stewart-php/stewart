<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Exception\HistoryException;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Message\HistoryResult;
use Stewart\Runtime\Ipc\Wire\HistoricalStatesFragment;

final readonly class HistoryProxy
{
    public function __construct(
        private HaSession $session,
        private HaCallSlots $slots,
    ) {}

    public function forward(WorkerHandle $handle, HistoryRequest $request): void
    {
        $this->slots->runBounded($handle, $request, fn(): HistoryResult => $this->fetchFromHomeAssistant($request), metered: false);
    }

    /** @throws HistoryException */
    private function fetchFromHomeAssistant(HistoryRequest $request): HistoryResult
    {
        if (!$this->session->isConnected()) {
            throw $request->createUnreachableFailure('Home Assistant is disconnected');
        }

        $history = $this->session->fetchHistory($request->entityId, $request->window, $request->detail);

        return new HistoryResult($request->correlationId, HistoricalStatesFragment::fromCollection($history->states));
    }
}
