<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

final readonly class GetCurrentUser implements HaCommand
{
    public function type(): string
    {
        return 'auth/current_user';
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
