<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
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
            default => null,
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
}
