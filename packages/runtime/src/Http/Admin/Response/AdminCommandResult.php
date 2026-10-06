<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin\Response;

final readonly class AdminCommandResult
{
    public function __construct(
        public bool $changed,
        public string $message,
        public ?string $warning,
    ) {}
}
