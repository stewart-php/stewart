<?php

declare(strict_types=1);

namespace Stewart\Contracts\App\Collection;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Collection\ListCollection;

/**
 * @internal
 * @extends ListCollection<AppId>
 */
final readonly class AppIdCollection extends ListCollection
{
    public function containsId(AppId $appId): bool
    {
        return $this->containsWhere(static fn(AppId $candidate): bool => $candidate->equals($appId));
    }

    /** @return list<string> */
    public function toStrings(): array
    {
        return $this->mapToList(static fn(AppId $appId): string => $appId->value);
    }
}
