<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use RuntimeException;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerPoolListener;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

final class RecordingPoolListener implements WorkerPoolListener
{
    /** @var list<WorkerMessage> */
    public array $messages = [];

    /** @var list<string> */
    public array $gone = [];

    /** @var list<int> */
    public array $spawned = [];

    public bool $failOnMessage = false;

    /** @param list<BrokerMessage> $sentOnSpawn */
    public function __construct(private readonly array $sentOnSpawn = []) {}

    public function workerSpawned(WorkerHandle $handle): void
    {
        $this->spawned[] = $handle->id->value;

        foreach ($this->sentOnSpawn as $message) {
            $handle->send($message);
        }
    }

    public function workerMessage(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->messages[] = $message;

        if ($this->failOnMessage) {
            throw new RuntimeException('handler blew up');
        }
    }

    public function workerGone(WorkerHandle $handle, string $reason): void
    {
        $this->gone[] = $handle->id . ': ' . $reason;
    }
}
