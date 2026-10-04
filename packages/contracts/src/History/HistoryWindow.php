<?php

declare(strict_types=1);

namespace Stewart\Contracts\History;

use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

final readonly class HistoryWindow
{
    /** @throws HistoryException */
    public function __construct(
        public Instant $startsAt,
        public Instant $endsAt,
    ) {
        if (!$endsAt->isAfter($startsAt)) {
            throw HistoryException::windowInvalid($startsAt, $endsAt);
        }
    }

    public function getDuration(): Duration
    {
        return $this->endsAt->elapsedSince($this->startsAt);
    }
}
