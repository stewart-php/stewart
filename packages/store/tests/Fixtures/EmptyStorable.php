<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Fixtures;

use Stewart\Contracts\Store\Storable;

final readonly class EmptyStorable implements Storable
{
    public function toStorage(): array
    {
        return [];
    }

    public static function fromStorage(array $data): static
    {
        return new self();
    }
}
