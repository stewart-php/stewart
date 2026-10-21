<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

final readonly class SourcePaths
{
    /**
     * @param list<string> $reloading
     * @param list<string> $restartOnly
     */
    public function __construct(
        public array $reloading,
        public array $restartOnly,
    ) {}
}
