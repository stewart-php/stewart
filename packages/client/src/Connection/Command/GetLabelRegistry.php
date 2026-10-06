<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

final readonly class GetLabelRegistry implements HaCommand
{
    public function type(): string
    {
        return 'config/label_registry/list';
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
