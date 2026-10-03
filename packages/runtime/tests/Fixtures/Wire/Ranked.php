<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class Ranked
{
    public function __construct(public Priority $priority) {}
}
