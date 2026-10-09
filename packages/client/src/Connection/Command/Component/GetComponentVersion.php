<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command\Component;

use Stewart\Client\Connection\Command\HaCommand;

final readonly class GetComponentVersion implements HaCommand
{
    public function type(): string
    {
        return 'stewart/version';
    }

    public function describe(): string
    {
        return $this->type();
    }

    public function toMessage(): array
    {
        return ['type' => $this->type()];
    }
}
