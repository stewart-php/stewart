<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Dispatch\DispatchSource;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Dispatch\QueueDiscardReason;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Support\Stream\OperatorStream;
use Stewart\Support\Time\Timers;

final readonly class ExposedCommandStreams
{
    public function __construct(
        private LocalDispatcher $dispatcher,
        private Timers $timers,
        private ExposedCommandSettlements $settlements,
    ) {}

    /** @return EventStream<ExposedCommand> */
    public function watchCommands(ResourceScope $scope, ExposedEntityKey $key): EventStream
    {
        /** @var DispatchSource<ExposedCommand> $source */
        $source = new DispatchSource($this->dispatcher, $scope, SubscriptionKind::ExposedCommand, Selector::exact($key->value));

        return new SettlingCommandStream(new OperatorStream($source, $this->timers), $this->settlements);
    }

    public function deliverCommand(string $commandId, ResourceScope $scope, ExposedEntityKey $key, ExposedCommand $command, ExposedHandle $handle): void
    {
        $this->settlements->openCommand($commandId, $command, $handle);

        $settlements = $this->settlements;
        $refuseDiscarded = static function (QueueDiscardReason $reason) use ($settlements, $command, $scope): void {
            $settlements->rejectCommand($command, self::describeDiscard($reason, $scope));
        };

        if (!$this->dispatcher->dispatchExposedCommand($scope, $key, $command, $refuseDiscarded)) {
            $this->settlements->acceptCommand($command);
        }
    }

    private static function describeDiscard(QueueDiscardReason $reason, ResourceScope $scope): string
    {
        return match ($reason) {
            QueueDiscardReason::Paused => \sprintf('App %s was paused before it handled the command.', $scope),
            QueueDiscardReason::Overflow => \sprintf('App %s dropped the command because it is falling behind.', $scope),
            QueueDiscardReason::Cancelled => \sprintf('App %s stopped watching commands for this entity.', $scope),
        };
    }
}
