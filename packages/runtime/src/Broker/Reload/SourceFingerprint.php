<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

final readonly class SourceFingerprint
{
    public function __construct(public string $digest) {}

    public function matches(self $other): bool
    {
        return $this->digest === $other->digest;
    }
}
