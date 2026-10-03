<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;

final readonly class AppDefinition
{
    /**
     * @param class-string $class
     * @param array<string, mixed> $options
     */
    public function __construct(
        public AppId $id,
        public string $class,
        public array $options = [],
        public ?int $worker = null,
    ) {}
}
