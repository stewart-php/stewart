<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedText;
use Stewart\Runtime\Model\ResourceScope;

final class WorkerExposedText extends WorkerExposedEntity implements ExposedText
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
        /** @var EventStream<TextCommand> */
        return $this->commandStreams->watchCommands($this->scope, $this->key);
    }

    public function getValue(): ?string
    {
        $value = $this->findStateValue();

        return \is_string($value) ? $value : null;
    }

    public function setValue(?string $value, ?array $attributes = null): void
    {
        $this->sendChange(new ExposedStateChange(new ExposedState($value), $attributes));
    }
}
