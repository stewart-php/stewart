<?php

declare(strict_types=1);

namespace Stewart\Runtime\Health;

final readonly class ReadinessVerdict
{
    /** @param list<string> $unreadyReasons */
    public function __construct(public array $unreadyReasons) {}

    public function isReady(): bool
    {
        return $this->unreadyReasons === [];
    }

    public function describeVerdict(): string
    {
        return $this->isReady() ? 'ready' : 'not ready: ' . implode('; ', $this->unreadyReasons);
    }
}
