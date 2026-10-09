<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ExposeEntityResult;
use Stewart\Runtime\Ipc\Message\ExposureAcknowledged;
use Stewart\Runtime\Ipc\Message\ReconfigureExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Worker\PendingRequests;

final class ExposureBrokerStub implements Transport
{
    /** @var list<object> */
    public array $sent = [];

    public ?ExposedEntitySnapshot $snapshot = null;

    public ?ExposureException $failure = null;

    public function __construct(private readonly PendingRequests $pending) {}

    public function send(object $message): void
    {
        $this->sent[] = $message;

        $answersWithSnapshot = $message instanceof ExposeEntityRequest || $message instanceof ReconfigureExposedEntityRequest;

        if (!$answersWithSnapshot && !$message instanceof UpdateExposedEntityRequest && !$message instanceof RemoveExposedEntityRequest) {
            return;
        }

        if ($this->failure !== null) {
            $this->pending->reject($message->correlationId, $this->failure);

            return;
        }

        $this->pending->resolve(
            $message->correlationId,
            $answersWithSnapshot ? new ExposeEntityResult($message->correlationId, $this->snapshot) : new ExposureAcknowledged($message->correlationId),
        );
    }

    public function receive(): ?object
    {
        return null;
    }

    public function close(): void {}

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @return list<T>
     */
    public function listSentOfType(string $class): array
    {
        return array_values(array_filter($this->sent, static fn(object $message): bool => $message instanceof $class));
    }
}
