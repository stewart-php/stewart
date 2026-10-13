<?php

declare(strict_types=1);

namespace App;

use Stewart\Contracts\Store\Storable;
use UnexpectedValueException;

final readonly class Thermostat implements Storable
{
    public function __construct(public float $target, public string $mode) {}

    public function toStorage(): array
    {
        return ['target' => $this->target, 'mode' => $this->mode];
    }

    public static function fromStorage(array $data): static
    {
        $target = $data['target'] ?? null;
        $mode = $data['mode'] ?? null;

        if (!is_numeric($target) || !\is_string($mode)) {
            throw new UnexpectedValueException('A stored thermostat needs a numeric target and a mode.');
        }

        return new self((float) $target, $mode);
    }
}
