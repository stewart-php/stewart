<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\StateUpdateBatch;
use Stewart\Runtime\Worker\StateCacheSync;

/** @implements BrokerMessageHandler<StateUpdateBatch> */
final readonly class StateUpdateBatchHandler implements BrokerMessageHandler
{
    public function __construct(
        private StateCacheSync $stateCacheSync,
        private LocalDispatcher $dispatcher,
    ) {}

    public function handledMessageClass(): string
    {
        return StateUpdateBatch::class;
    }

    /** @param StateUpdateBatch $message */
    public function handle(BrokerMessage $message): void
    {
        $this->dispatcher->dispatchStateChanges($this->stateCacheSync->applyStateUpdates($message->updates->collection));
    }
}
