<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Broker\Exposure\Collection\ExposedEntityCommandCollection;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Model\WorkerId;

// Home Assistant gives up on a command after its timeout, so older entries are dropped instead of waiting forever.
final class PendingExposureCommands
{
    /** @var array<string, ExposedEntityCommand> */
    private array $commandsById = [];

    /** @var array<string, MonotonicTime> */
    private array $expiriesById = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly ExposeConfig $expose,
    ) {}

    public function recordCommand(ExposedEntityCommand $command): void
    {
        $now = $this->clock->getMonotonicTime();
        $this->forgetExpiredCommands($now);
        $this->commandsById[$command->commandId] = $command;
        $this->expiriesById[$command->commandId] = $now->plus($this->expose->commandTimeout);
    }

    public function takeCommandAnsweredBy(string $commandId, WorkerId $workerId): ?ExposedEntityCommand
    {
        $command = $this->commandsById[$commandId] ?? null;

        if ($command?->owner?->equals($workerId) !== true) {
            return null;
        }

        $this->forgetCommand($commandId);

        return $command;
    }

    public function takeCommandsOf(WorkerId $workerId): ExposedEntityCommandCollection
    {
        $taken = ExposedEntityCommandCollection::fromCommands(array_filter($this->commandsById, static fn(ExposedEntityCommand $command): bool => $command->owner?->equals($workerId) === true));

        foreach ($taken as $command) {
            $this->forgetCommand($command->commandId);
        }

        return $taken;
    }

    private function forgetExpiredCommands(MonotonicTime $now): void
    {
        foreach ($this->expiriesById as $commandId => $expiry) {
            if ($now->isAfter($expiry)) {
                $this->forgetCommand($commandId);
            }
        }
    }

    private function forgetCommand(string $commandId): void
    {
        unset($this->commandsById[$commandId], $this->expiriesById[$commandId]);
    }
}
