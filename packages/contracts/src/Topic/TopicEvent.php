<?php

declare(strict_types=1);

namespace Stewart\Contracts\Topic;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Instant;

final readonly class TopicEvent
{
    /** @param array<array-key, mixed>|scalar|null $payload */
    public function __construct(
        public string $topic,
        public bool|int|float|string|array|null $payload,
        public ?AppId $publisher,
        public Instant $publishedAt,
    ) {}
}
