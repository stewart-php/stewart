<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Runtime\Ipc\Message\HistoryFailed;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Message\HistoryResult;
use Stewart\Runtime\Ipc\Wire\HistoricalStatesFragment;
use Throwable;

use function Amp\async;

final readonly class HistoryProxy
{
    public function __construct(
        private HaSession $session,
        private LoggerInterface $logger,
    ) {}

    public function forward(WorkerHandle $handle, HistoryRequest $request): void
    {
        async(function () use ($handle, $request): void {
            try {
                $handle->send($this->fetchFromHomeAssistant($request));
            } catch (Throwable $e) {
                $this->logger->error('History reply failed', [
                    'exception' => $e,
                    'worker' => $handle->id->value,
                    'correlation_id' => $request->correlationId->value,
                ]);
            }
        })->ignore();
    }

    private function fetchFromHomeAssistant(HistoryRequest $request): HistoryResult|HistoryFailed
    {
        try {
            if (!$this->session->isConnected()) {
                throw HistoryException::unreachable($request->entityId, 'Home Assistant is disconnected');
            }

            $history = $this->session->fetchHistory($request->entityId, $request->window, $request->detail);

            return new HistoryResult($request->correlationId, HistoricalStatesFragment::fromCollection($history->states));
        } catch (HistoryException $e) {
            return HistoryFailed::fromException($request->correlationId, $e);
        } catch (Throwable $e) {
            return HistoryFailed::fromException($request->correlationId, HistoryException::unreachable($request->entityId, $e->getMessage(), $e));
        }
    }
}
