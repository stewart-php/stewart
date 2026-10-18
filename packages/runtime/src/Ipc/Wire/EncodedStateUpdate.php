<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use JsonException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Support\State\StateUpdate;

final class EncodedStateUpdate
{
    private ?string $json = null;

    public function __construct(public readonly StateUpdate $update) {}

    /** @throws JsonException|StewartException */
    public function encodeToJson(WireMapper $mapper): string
    {
        return $this->json ??= $mapper->encodeObject($this->update);
    }
}
