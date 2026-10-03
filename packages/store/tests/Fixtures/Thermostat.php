<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Fixtures;

use InvalidArgumentException;
use Stewart\Contracts\Store\Storable;

final readonly class Thermostat implements Storable
{
    public function __construct(
        public float $target,
        public string $mode,
    ) {}

    public function toStorage(): array
    {
        return ['target' => $this->target, 'mode' => $this->mode];
    }

    public static function fromStorage(array $data): static
    {
        $target = $data['target'] ?? null;
        $mode = $data['mode'] ?? null;

        if (!\is_float($target) && !\is_int($target)) {
            throw new InvalidArgumentException('target is missing');
        }

        if (!\is_string($mode)) {
            throw new InvalidArgumentException('mode is missing');
        }

        return new self((float) $target, $mode);
    }
}
