<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

final readonly class DeclareSupportedFeatures implements HaCommand
{
    public function type(): string
    {
        return 'supported_features';
    }

    public function describe(): string
    {
        return $this->type();
    }

    public function toMessage(): array
    {
        return ['type' => $this->type(), 'features' => ['coalesce_messages' => 1]];
    }
}
