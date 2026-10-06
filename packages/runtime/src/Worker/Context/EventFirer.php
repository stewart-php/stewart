<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Context;

use Amp\CancelledException;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Subject\EventFireSubject;
use Stewart\Support\Time\Deadlines;
use Throwable;

final readonly class EventFirer
{
    public function __construct(
        private Transport $transport,
        private PendingRequests $pending,
        private ConnectionStatus $connection,
        private Deadlines $deadlines,
        private Duration $eventFireTimeout,
    ) {}

    /** @throws EventFireException */
    public function fireEvent(ResourceScope $scope, EventPayload $payload): EventContext
    {
        if (!$this->connection->connected) {
            throw EventFireException::unreachable($payload->eventType, 'Home Assistant is disconnected');
        }

        $fire = $this->pending->open(new EventFireSubject($payload->eventType));

        try {
            $this->sendRequest(new EventFireRequest($fire->correlationId, $scope, $payload->eventType, $payload->data));

            return $fire->getFuture()->await($this->deadlines->timeout($this->eventFireTimeout));
        } catch (CancelledException $e) {
            throw EventFireException::timedOut($payload->eventType, \sprintf('no answer from the broker within %s', $this->eventFireTimeout), $e);
        } finally {
            $this->pending->forget($fire->correlationId);
        }
    }

    /** @throws EventFireException */
    private function sendRequest(EventFireRequest $request): void
    {
        try {
            $this->transport->send($request);
        } catch (Throwable $e) {
            throw $e instanceof TransportException && $e->reason === TransportError::Unencodable
                ? EventFireException::rejected($request->eventType, $e->getMessage(), null, $e)
                : EventFireException::unreachable($request->eventType, 'the broker is gone: ' . $e->getMessage(), $e);
        }
    }
}
