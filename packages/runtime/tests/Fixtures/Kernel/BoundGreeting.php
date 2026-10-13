<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Kernel;

final readonly class BoundGreeting
{
    public function __construct(public string $text) {}

    public static function createForName(string $greetedName): self
    {
        return new self('hello ' . $greetedName);
    }
}
