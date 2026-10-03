<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Worker\StateCacheSync;

/** @implements BrokerMessageHandler<StateChangeBatch> */
final readonly class StateChangeBatchHandler implements BrokerMessageHandler
{
    public function __construct(
        private StateCacheSync $stateCacheSync,
        private LocalDispatcher $dispatcher,
    ) {}

    public function handledMessageClass(): string
    {
        return StateChangeBatch::class;
    }

    /** @param StateChangeBatch $message */
    public function handle(BrokerMessage $message): void
    {
        foreach ($message->changes->collection as $change) {
            $this->stateCacheSync->applyStateChange($change);
            $this->dispatcher->dispatchStateChange($change);
        }
    }
}
