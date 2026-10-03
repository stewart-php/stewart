<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class IpcMessage
{
    public function __construct(public string $tag) {}
}
