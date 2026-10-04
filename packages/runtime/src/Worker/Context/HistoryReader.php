<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Context;

use Amp\CancelledException;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\PendingHistoryQueries;
use Stewart\Support\Time\Deadlines;
use Throwable;

final readonly class HistoryReader
{
    public function __construct(
        private Transport $transport,
        private PendingHistoryQueries $pending,
        private ConnectionStatus $connection,
        private Deadlines $deadlines,
        private Clock $clock,
        private Duration $historyQueryTimeout,
    ) {}

    /** @throws HistoryException */
    public function fetchHistory(ResourceScope $scope, EntityId $entityId, HistoryQuery $query): EntityStateHistory
    {
        $window = $query->resolveWindowAt($this->clock->getNow());

        if (!$this->connection->connected) {
            throw HistoryException::unreachable($entityId, 'Home Assistant is disconnected');
        }

        $pending = $this->pending->open($entityId, $window);

        try {
            $this->sendRequest(new HistoryRequest($pending->correlationId, $scope, $entityId, $window, $query->includesAttributes));

            return $pending->getFuture()->await($this->deadlines->timeout($this->historyQueryTimeout));
        } catch (CancelledException $e) {
            throw HistoryException::timedOut($entityId, \sprintf('no answer from the broker within %s', $this->historyQueryTimeout), $e);
        } finally {
            $this->pending->forget($pending->correlationId);
        }
    }

    /** @throws HistoryException */
    private function sendRequest(HistoryRequest $request): void
    {
        try {
            $this->transport->send($request);
        } catch (Throwable $e) {
            throw $e instanceof TransportException && $e->reason === TransportError::Unencodable
                ? HistoryException::rejected($request->entityId, $e->getMessage(), null, $e)
                : HistoryException::unreachable($request->entityId, 'the broker is gone: ' . $e->getMessage(), $e);
        }
    }
}
