<?php

declare(strict_types=1);

namespace Stewart\Contracts\Identity;

use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;

// Every service call Stewart makes carries the token's user id, so it is known before callService() returns.
final readonly class StewartIdentity
{
    public function __construct(public ?string $haUserId) {}

    public function wasCausedByStewart(StateChange|EntityState $subject): bool
    {
        $context = $subject instanceof StateChange ? $subject->findCausingContext() : $subject->context;

        return $this->haUserId !== null && $this->haUserId !== '' && $context?->userId === $this->haUserId;
    }
}
