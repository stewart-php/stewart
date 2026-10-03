<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

final class WorkerProbeSequence
{
    private int $nonce = 0;

    public function getCurrentNonce(): int
    {
        return $this->nonce;
    }

    public function advanceNonce(): int
    {
        return ++$this->nonce;
    }
}
