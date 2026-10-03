<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Worker\StateCacheSync;

/** @implements BrokerMessageHandler<StateSnapshot> */
final readonly class StateSnapshotHandler implements BrokerMessageHandler
{
    public function __construct(private StateCacheSync $stateCacheSync) {}

    public function handledMessageClass(): string
    {
        return StateSnapshot::class;
    }

    /** @param StateSnapshot $message */
    public function handle(BrokerMessage $message): void
    {
        $this->stateCacheSync->seedFromSnapshot($message->states->collection, $message->revision);
    }
}
