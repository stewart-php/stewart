<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Ipc\Message\ExposedCommandAnswered;
use Stewart\Runtime\Ipc\Message\ExposedEntityCommanded;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;

final readonly class ExposureCommandRouter
{
    public function __construct(
        private HaSession $session,
        private WorkerSlotRegistry $slots,
        private AppPauseRegistry $pauses,
        private PendingExposureCommands $pending,
    ) {}

    public function routeCommand(ExposedEntityCommand $command): void
    {
        if ($this->pauses->isPaused($command->appId)) {
            $this->session->rejectExposedEntityCommand($command, \sprintf('App %s is paused.', $command->appId));

            return;
        }

        $handle = $this->slots->findHandleForWorker($command->owner);

        if ($handle === null) {
            $this->session->rejectExposedEntityCommand($command, \sprintf('App %s is not running.', $command->appId));

            return;
        }

        $this->pending->recordCommand($command);
        $handle->send(new ExposedEntityCommanded($command->commandId, ResourceScope::forApp($command->appId), $command->key, $command->command));
    }

    public function completeCommand(WorkerHandle $handle, ExposedCommandAnswered $answer): void
    {
        $command = $this->pending->takeCommandAnsweredBy($answer->commandId, $handle->id);

        if ($command === null) {
            return;
        }

        if ($answer->accepted) {
            $this->session->acceptExposedEntityCommand($command);

            return;
        }

        $this->session->rejectExposedEntityCommand($command, $answer->rejection ?? \sprintf('App %s rejected the command.', $command->appId));
    }

    public function failCommandsOf(WorkerId $workerId): void
    {
        foreach ($this->pending->takeCommandsOf($workerId) as $command) {
            $this->session->rejectExposedEntityCommand($command, \sprintf('App %s stopped while handling the command.', $command->appId));
        }
    }
}
