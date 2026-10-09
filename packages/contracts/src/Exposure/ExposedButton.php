<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\ButtonPress;

interface ExposedButton extends ExposedEntity
{
    /** @return EventStream<ButtonPress> */
    public function watchCommands(): EventStream;
}
