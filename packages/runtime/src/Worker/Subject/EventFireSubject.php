<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Subject;

use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Worker\PendingRequestSubject;

/** @implements PendingRequestSubject<EventContext> */
final readonly class EventFireSubject implements PendingRequestSubject
{
    public function __construct(public string $eventType) {}

    public function getResultClass(): string
    {
        return EventContext::class;
    }

    public function createUnreachableFailure(string $detail): EventFireException
    {
        return EventFireException::unreachable($this->eventType, $detail);
    }
}
