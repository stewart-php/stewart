<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;

final readonly class TypedOptionsApp implements App
{
    public function __construct(
        public bool $enabled = false,
        public int $retries = 0,
        public float $ratio = 0.0,
        public string $label = '',
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
