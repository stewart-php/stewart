<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedSelect;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Runtime\Model\ResourceScope;

final class WorkerExposedSelect extends WorkerExposedEntity implements ExposedSelect
{
    public function __construct(
        ExposureRequester $requester,
        ExposedHandleRegistry $handles,
        ResourceScope $scope,
        ExposedEntityKey $key,
        private readonly ExposedCommandStreams $commandStreams,
    ) {
        parent::__construct($requester, $handles, $scope, $key);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<SelectCommand> */
        return $this->commandStreams->watchCommands($this->scope, $this->key);
    }

    public function getOption(): ?string
    {
        $value = $this->findStateValue();

        return \is_string($value) ? $value : null;
    }

    public function setOption(?string $option, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($option), $attributes));
    }
}
