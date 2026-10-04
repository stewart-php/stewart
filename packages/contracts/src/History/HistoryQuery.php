<?php

declare(strict_types=1);

namespace Stewart\Contracts\History;

use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

final readonly class HistoryQuery
{
    private function __construct(
        private ?Duration $lookback,
        private ?HistoryWindow $fixedWindow,
        public HistoryDetail $detail,
    ) {}

    /** @throws TimeException */
    public static function lastFor(Duration $lookback): self
    {
        return new self($lookback->requireAtLeastOneMillisecond('a history lookback'), null, HistoryDetail::StateChanges);
    }

    /** @throws HistoryException */
    public static function between(Instant $startsAt, Instant $endsAt): self
    {
        return new self(null, new HistoryWindow($startsAt, $endsAt), HistoryDetail::StateChanges);
    }

    public function withAttributes(): self
    {
        return $this->detail === HistoryDetail::StateChanges ? new self($this->lookback, $this->fixedWindow, HistoryDetail::StateChangesWithAttributes) : $this;
    }

    public function withAttributeChanges(): self
    {
        return new self($this->lookback, $this->fixedWindow, HistoryDetail::AllChanges);
    }

    public function resolveWindowAt(Instant $now): HistoryWindow
    {
        if ($this->fixedWindow !== null) {
            return $this->fixedWindow;
        }

        \assert($this->lookback !== null);

        return new HistoryWindow($now->minus($this->lookback), $now);
    }
}
