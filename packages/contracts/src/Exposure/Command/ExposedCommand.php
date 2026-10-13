<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Command;

use Stewart\Contracts\Exposure\Action\ActionCall;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Wire\WireVariants;

#[WireVariants('platform', [
    ExposedPlatform::Switch->value => SwitchCommand::class,
    ExposedPlatform::Button->value => ButtonPress::class,
    ExposedPlatform::Number->value => NumberCommand::class,
    ExposedPlatform::Select->value => SelectCommand::class,
    ExposedPlatform::Text->value => TextCommand::class,
    ExposedPlatform::Time->value => TimeCommand::class,
    ExposedPlatform::Date->value => DateCommand::class,
    ExposedPlatform::DateTime->value => DateTimeCommand::class,
    ExposedPlatform::Action->value => ActionCall::class,
])]
interface ExposedCommand
{
    public function getContext(): EventContext;

    public function getRequestedState(): ?ExposedState;
}
