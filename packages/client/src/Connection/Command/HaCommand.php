<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

interface HaCommand
{
    public function type(): string;

    public function describe(): string;

    /** @return array<string, mixed> */
    public function toMessage(): array;
}
