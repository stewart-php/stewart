<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

/** @internal */
final class CrossingLatches
{
    /** @var array<string, bool> */
    private array $firedByKey = [];

    public function isKnown(string $key): bool
    {
        return \array_key_exists($key, $this->firedByKey);
    }

    public function isFired(string $key): bool
    {
        return $this->firedByKey[$key] ?? false;
    }

    public function recordArmed(string $key): void
    {
        $this->firedByKey[$key] = false;
    }

    public function recordFired(string $key): void
    {
        $this->firedByKey[$key] = true;
    }

    public function forget(string $key): void
    {
        unset($this->firedByKey[$key]);
    }
}
