<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

final class SourceChangeTracker
{
    private ?SourceFingerprint $pending = null;

    public function __construct(private SourceFingerprint $applied) {}

    // A change counts once two scans in a row agree on it, so a save that writes several files counts once.
    public function findSettledChange(SourceFingerprint $current): ?SourceFingerprint
    {
        if ($current->matches($this->applied)) {
            $this->pending = null;

            return null;
        }

        if ($this->pending === null || !$current->matches($this->pending)) {
            $this->pending = $current;

            return null;
        }

        return $current;
    }

    public function markApplied(SourceFingerprint $fingerprint): void
    {
        $this->applied = $fingerprint;
        $this->pending = null;
    }
}
