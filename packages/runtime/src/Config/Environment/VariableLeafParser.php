<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\VariableNode;

final readonly class VariableLeafParser implements LeafParser
{
    public function __construct(private InlineValueInference $inference) {}

    public function supports(NodeInterface $node): bool
    {
        return $node::class === VariableNode::class;
    }

    public function parse(string $name, string $raw): mixed
    {
        return $this->inference->inferValue($raw);
    }
}
