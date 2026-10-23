<?php

declare(strict_types=1);

namespace Stewart\Contracts\Action;

use Stewart\Contracts\State\EventContext;

final readonly class ActionResponse
{
    /** @param array<string, mixed> $response */
    public function __construct(
        public string $domain,
        public string $action,
        public array $response = [],
        public ?EventContext $context = null,
    ) {}
}
