<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

final readonly class ScalarConsumer
{
    public function __construct(public string $entity) {}
}
