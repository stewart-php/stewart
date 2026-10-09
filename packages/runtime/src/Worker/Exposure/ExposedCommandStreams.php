<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Stream\OperatorStream;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Dispatch\DispatchSource;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionKind;

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

        if (!$this->dispatcher->dispatchExposedCommand($scope, $key, $command)) {
            $this->settlements->acceptCommand($command);
        }
    }
}
