<?php

declare(strict_types=1);

namespace Stewart\Contracts\History;

enum HistoryDetail: string
{
    case StateChanges = 'state_changes';
    case StateChangesWithAttributes = 'state_changes_with_attributes';
    case AllChanges = 'all_changes';

    public function includesAttributes(): bool
    {
        return $this !== self::StateChanges;
    }

    public function includesAttributeOnlyChanges(): bool
    {
        return $this === self::AllChanges;
    }
}
