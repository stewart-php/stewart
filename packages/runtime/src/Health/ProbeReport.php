<?php

declare(strict_types=1);

namespace Stewart\Runtime\Health;

use Stewart\Contracts\Wire\ListOf;

final readonly class ProbeReport
{
    /** @param list<string> $reasons */
    public function __construct(
        public ProbeStatus $status,
        #[ListOf('string')]
        public array $reasons,
    ) {}

    public static function alive(): self
    {
        return new self(ProbeStatus::Alive, []);
    }

    public static function fromVerdict(ReadinessVerdict $verdict): self
    {
        return new self($verdict->isReady() ? ProbeStatus::Ready : ProbeStatus::NotReady, $verdict->unreadyReasons);
    }
}
