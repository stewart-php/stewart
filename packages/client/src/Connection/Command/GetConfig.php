<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

final readonly class GetConfig implements HaCommand
{
    public function type(): string
    {
        return 'get_config';
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
