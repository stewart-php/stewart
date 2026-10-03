<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use JsonException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\Json\WireMapper;

final class EncodedStateChange
{
    private ?string $json = null;

    public function __construct(public readonly StateChange $change) {}

    /** @throws JsonException|StewartException */
    public function encodeToJson(WireMapper $mapper): string
    {
        return $this->json ??= $mapper->encodeObject($this->change);
    }
}
