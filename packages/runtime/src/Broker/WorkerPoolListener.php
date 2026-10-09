<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Ipc\Message\WorkerMessage;

interface WorkerPoolListener
{
    public function workerSpawned(WorkerHandle $handle): void;

    public function workerMessage(WorkerHandle $handle, WorkerMessage $message): void;

    public function workerGone(WorkerHandle $handle, string $reason): void;

    public function workerQuarantined(WorkerSlot $slot): void;
}
