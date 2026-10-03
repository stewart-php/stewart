<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Store\StoreHealth;

#[IpcMessage(tag: 'pong')]
final readonly class Pong implements WorkerMessage
{
    /** @param list<AppActivityReport> $apps */
    public function __construct(
        public int $nonce,
        public Duration $loopLag,
        public int $memoryBytes,
        #[ListOf(AppActivityReport::class)]
        public array $apps = [],
        public ?StoreHealth $store = null,
    ) {}
}
