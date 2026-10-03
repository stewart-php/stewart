<?php

declare(strict_types=1);

namespace Stewart\Runtime\Lifecycle;

enum AppState: string
{
    case Declared = 'declared';
    case Constructing = 'constructing';
    case Constructed = 'constructed';
    case Initializing = 'initializing';
    case Initialized = 'initialized';
    case Running = 'running';
    case Disposing = 'disposing';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Disposed = 'disposed';
    case Abandoned = 'abandoned';

    public function canEnter(self $next): bool
    {
        return \in_array($next, match ($this) {
            self::Declared => [self::Constructing, self::Skipped, self::Failed, self::Abandoned],
            self::Constructing => [self::Constructed, self::Failed, self::Skipped, self::Abandoned],
            self::Constructed => [self::Initializing, self::Skipped, self::Abandoned],
            self::Initializing => [self::Running, self::Initialized, self::Failed, self::Abandoned],
            self::Initialized, self::Running => [self::Disposing, self::Abandoned],
            self::Disposing => [self::Disposed, self::Abandoned],
            self::Failed, self::Skipped, self::Disposed, self::Abandoned => [],
        }, true);
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Failed, self::Skipped, self::Disposed, self::Abandoned => true,
            default => false,
        };
    }
}
