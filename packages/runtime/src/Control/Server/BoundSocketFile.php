<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Server;

final readonly class BoundSocketFile
{
    public function __construct(
        public string $path,
        public int $device,
        public int $inode,
    ) {}

    public function isSameFileAs(self $other): bool
    {
        return $this->device === $other->device && $this->inode === $other->inode;
    }
}
