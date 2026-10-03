<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Symfony\Component\Filesystem\Path;

final readonly class ProjectRoot
{
    public function __construct(public string $path) {}

    public function resolvePath(string $path): string
    {
        return Path::makeAbsolute($path, $this->path);
    }
}
