<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\NodeInterface;

final readonly class FloatLeafParser implements LeafParser
{
    public function supports(NodeInterface $node): bool
    {
        return $node::class === FloatNode::class;
    }

    public function parse(string $name, string $raw): float
    {
        $value = trim($raw);

        return is_numeric($value) ? (float) $value : throw ConfigurationException::environmentValueInvalid($name, 'a number', $raw);
    }
}
