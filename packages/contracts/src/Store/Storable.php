<?php

declare(strict_types=1);

namespace Stewart\Contracts\Store;

interface Storable
{
    /** @return array<string, mixed> */
    public function toStorage(): array;

    /** @param array<string, mixed> $data */
    public static function fromStorage(array $data): static;
}
