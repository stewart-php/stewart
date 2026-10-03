<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Connection\ConnectionRestored;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\StateResynced;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\StateCacheSync;

/** @implements BrokerMessageHandler<StateResynced> */
final readonly class StateResyncedHandler implements BrokerMessageHandler
{
    public function __construct(
        private StateCacheSync $stateCacheSync,
        private ConnectionStatus $connection,
        private LocalDispatcher $dispatcher,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return StateResynced::class;
    }

    /** @param StateResynced $message */
    public function handle(BrokerMessage $message): void
    {
        // Before the revision check, so a stale resync also ends the outage.
        $outageEnded = $this->connection->markRestored();

        if (!$this->stateCacheSync->isOlderThan($message->revision)) {
            if ($outageEnded) {
                $this->dispatchRestored($this->clock->getNow(), $message, 0);
            }

            return;
        }

        $restoredAt = $this->clock->getNow();
        $reconstructed = $this->stateCacheSync->replaceWithResyncedStates($message->states->collection, $message->revision, $restoredAt);

        foreach ($reconstructed as $change) {
            $this->dispatcher->dispatchStateChange($change);
        }

        $this->logger->info('State rebuilt after losing Home Assistant', [
            'entities' => $this->stateCacheSync->countEntities(),
            'outage' => (string) $message->outage,
            'reconstructed_changes' => \count($reconstructed),
        ]);

        $this->dispatchRestored($restoredAt, $message, \count($reconstructed));
    }

    private function dispatchRestored(Instant $restoredAt, StateResynced $message, int $reconstructedChanges): void
    {
        $this->dispatcher->dispatchConnection(new ConnectionRestored(
            restoredAt: $restoredAt,
            entityCount: $this->stateCacheSync->countEntities(),
            outage: $message->outage,
            reconstructedChanges: $reconstructedChanges,
        ));
    }
}
