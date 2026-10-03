<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

enum FileOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Deleted = 'deleted';

    public function isChange(): bool
    {
        return $this !== self::Unchanged;
    }
}
