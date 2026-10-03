<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'publish')]
final readonly class Publish implements WorkerMessage
{
    /** @param array<array-key, mixed>|scalar|null $payload */
    public function __construct(
        public string $topic,
        public bool|int|float|string|array|null $payload,
        public ResourceScope $publisherScope,
        public Instant $publishedAt,
    ) {}
}
