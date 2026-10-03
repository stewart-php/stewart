<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Fixtures;

use Stewart\Contracts\Store\Storable;

final readonly class ListStorable implements Storable
{
    public function toStorage(): array
    {
        /** @phpstan-ignore return.type (the point of the fixture: the codec has to catch this) */
        return ['first', 'second'];
    }

    public static function fromStorage(array $data): static
    {
        return new self();
    }
}
