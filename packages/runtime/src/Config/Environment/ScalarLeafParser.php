<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\ScalarNode;

final readonly class ScalarLeafParser implements LeafParser
{
    public function supports(NodeInterface $node): bool
    {
        return $node::class === ScalarNode::class || $node::class === EnumNode::class;
    }

    public function parse(string $name, string $raw): string
    {
        return $raw;
    }
}
