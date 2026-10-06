<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin\Response;

final readonly class AdminFailure
{
    public function __construct(
        public string $reason,
        public string $message,
    ) {}
}
