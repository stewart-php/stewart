<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Rule;

final readonly class EntityIdParameter
{
    /** @param class-string $class */
    public function __construct(
        public string $class,
        public string $method,
        public string $name,
        public int $position = 0,
        public bool $variadic = false,
    ) {}
}
