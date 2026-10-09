<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\DateCommand;
use Stewart\Contracts\Exposure\Command\DateTimeCommand;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\State\EventContext;

final readonly class ComponentCommand implements ComponentSessionEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $commandId,
        public AppId $appId,
        public ExposedEntityKey $key,
        public ComponentCommandAction $action,
        public array $data,
        public EventContext $context,
    ) {}

    // Null when the action or its data does not fit the platform.
    public function readExposedCommand(ExposedPlatform $platform): ?ExposedCommand
    {
        return match ($platform) {
            ExposedPlatform::Switch => match ($this->action) {
                ComponentCommandAction::TurnOn => new SwitchCommand(SwitchAction::TurnOn, $this->context),
                ComponentCommandAction::TurnOff => new SwitchCommand(SwitchAction::TurnOff, $this->context),
                default => null,
            },
            ExposedPlatform::Button => $this->action === ComponentCommandAction::Press ? new ButtonPress($this->context) : null,
            ExposedPlatform::Number => $this->action === ComponentCommandAction::SetValue ? $this->readNumberCommand() : null,
            ExposedPlatform::Select => $this->action === ComponentCommandAction::SelectOption ? $this->readSelectCommand() : null,
            ExposedPlatform::Text => $this->action === ComponentCommandAction::SetValue ? $this->readTextCommand() : null,
            ExposedPlatform::Time => $this->action === ComponentCommandAction::SetValue ? $this->readTimeCommand() : null,
            ExposedPlatform::Date => $this->action === ComponentCommandAction::SetValue ? $this->readDateCommand() : null,
            ExposedPlatform::DateTime => $this->action === ComponentCommandAction::SetValue ? $this->readDateTimeCommand() : null,
            ExposedPlatform::Sensor, ExposedPlatform::BinarySensor => null,
        };
    }

    private function readNumberCommand(): ?NumberCommand
    {
        $value = $this->data['value'] ?? null;

        return \is_int($value) || \is_float($value) ? new NumberCommand($value, $this->context) : null;
    }

    private function readSelectCommand(): ?SelectCommand
    {
        $option = $this->data['option'] ?? null;

        return \is_string($option) ? new SelectCommand($option, $this->context) : null;
    }

    private function readTextCommand(): ?TextCommand
    {
        $value = $this->readStringValue();

        return $value === null ? null : new TextCommand($value, $this->context);
    }

    private function readTimeCommand(): ?TimeCommand
    {
        $value = $this->readStringValue();
        $time = $value === null ? null : CalendarStateFormat::parseTime($value);

        return $time === null ? null : new TimeCommand($time, $this->context);
    }

    private function readDateCommand(): ?DateCommand
    {
        $value = $this->readStringValue();
        $date = $value === null ? null : CalendarStateFormat::parseDate($value);

        return $date === null ? null : new DateCommand($date, $this->context);
    }

    private function readDateTimeCommand(): ?DateTimeCommand
    {
        $value = $this->readStringValue();
        $moment = $value === null ? null : CalendarStateFormat::parseDateTime($value);

        return $moment === null ? null : new DateTimeCommand($moment, $this->context);
    }

    private function readStringValue(): ?string
    {
        $value = $this->data['value'] ?? null;

        return \is_string($value) ? $value : null;
    }
}
