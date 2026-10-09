<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\ExposedButton;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;

final class RecordingExposedButton extends RecordingExposedEntity implements ExposedButton
{
    public function __construct(
        ExposedEntityKey $key,
        public readonly ButtonConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<ButtonPress> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(ButtonPress $command): void
    {
        $this->deliverCommand($command);
    }
}
