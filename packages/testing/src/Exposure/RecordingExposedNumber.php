<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedNumber;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\NumberConfig;

final class RecordingExposedNumber extends RecordingExposedEntity implements ExposedNumber
{
    public function __construct(
        ExposedEntityKey $key,
        public readonly NumberConfig $config,
        ?ExposedEntitySnapshot $seeded,
    ) {
        parent::__construct($key, $seeded);
    }

    public function getValue(): int|float|null
    {
        $value = $this->findStateValue();

        return \is_int($value) || \is_float($value) ? $value : null;
    }

    public function setValue(int|float|null $value, ?array $attributes = null): void
    {
        $this->recordChange(new ExposedStateChange(new ExposedState($this->config->formatState($value)), $attributes));
    }

    public function watchCommands(): EventStream
    {
        /** @var EventStream<NumberCommand> */
        return $this->watchPushedCommands();
    }

    /** @throws CommandException */
    public function pushCommand(NumberCommand $command): void
    {
        $this->deliverCommand($command);
    }
}
