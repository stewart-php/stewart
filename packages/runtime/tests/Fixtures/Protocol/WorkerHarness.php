<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Amp\Cancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use Closure;
use PHPUnit\Framework\Assert;
use Stewart\Contracts\State\Collection\StateChangeCollection;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\WorkerProcess;
use Stewart\Runtime\Broker\WorkerSpawner;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\StateChangesFragment;

use function Amp\async;

final class WorkerHarness
{
    private const float RECEIVE_DEADLINE_SECONDS = 10;

    private const float JOIN_DEADLINE_SECONDS = 5;

    private const float SPAWN_DEADLINE_SECONDS = 10;

    private bool $joined = false;

    private function __construct(private readonly WorkerProcess $process) {}

    public static function boot(WorkerSpawner $spawner, Bootstrap $bootstrap, EntityStatesFragment $states): self
    {
        $harness = new self($spawner->spawn($bootstrap->workerId, new TimeoutCancellation(self::SPAWN_DEADLINE_SECONDS)));
        $harness->send($bootstrap);
        $harness->send(new StateSnapshot(states: $states, revision: 1));

        return $harness;
    }

    public function send(object $message): void
    {
        $this->process->transport()->send($message);
    }

    public function sendChanges(StateChange ...$changes): void
    {
        $this->send(new StateChangeBatch(StateChangesFragment::fromCollection(StateChangeCollection::fromChanges($changes))));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param (Closure(T): bool)|null $matches
     * @param (Closure(object): void)|null $collect
     * @return T
     */
    public function receiveUntil(string $class, ?Closure $matches = null, ?Closure $collect = null): object
    {
        $deadline = new TimeoutCancellation(self::RECEIVE_DEADLINE_SECONDS);

        while (true) {
            $message = $this->receiveNextMessage($deadline);

            if ($message === null) {
                Assert::fail(\sprintf('The worker hung up while the test waited for %s.', $class));
            }

            if ($message instanceof $class && ($matches === null || $matches($message))) {
                return $message;
            }

            $collect?->__invoke($message);
        }
    }

    public function shutDown(string $reason): string
    {
        $this->send(new Shutdown($reason, Duration::seconds(5.0)));

        return $this->join();
    }

    public function join(): string
    {
        /** @var Future<string> $exit */
        $exit = async(fn(): string => $this->process->join());
        $exit->ignore();
        $summary = $exit->await(new TimeoutCancellation(self::JOIN_DEADLINE_SECONDS));
        $this->joined = true;

        return $summary;
    }

    public function close(): void
    {
        if (!$this->joined) {
            $this->process->close();
        }
    }

    private function receiveNextMessage(Cancellation $deadline): ?object
    {
        $transport = $this->process->transport();

        /** @var Future<object|null> $pending */
        $pending = async(static fn(): ?object => $transport->receive());

        return $pending->await($deadline);
    }
}
