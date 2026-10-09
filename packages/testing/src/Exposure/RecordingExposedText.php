<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\ExposedText;
use Stewart\Contracts\Exposure\TextConfig;

final class RecordingExposedText extends RecordingExposedEntity implements ExposedText
{
    public function __construct(
        ExposedEntityKey $key,
        public readonly TextConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }

    public function getValue(): ?string
    {
        $value = $this->findStateValue();

        return \is_string($value) ? $value : null;
    }

    public function setValue(?string $value, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($value), $attributes));
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<TextCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(TextCommand $command): void
    {
        $this->deliverCommand($command);
    }
}
