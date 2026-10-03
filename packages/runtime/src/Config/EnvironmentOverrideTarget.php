<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Symfony\Component\Config\Definition\NodeInterface;

/** @internal */
final readonly class EnvironmentOverrideTarget
{
    /** @param non-empty-list<string> $path */
    public function __construct(
        public array $path,
        public NodeInterface $node,
        public bool $readsSecretFile = false,
    ) {}
}
