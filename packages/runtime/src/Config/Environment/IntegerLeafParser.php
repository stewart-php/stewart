<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\NodeInterface;

final readonly class IntegerLeafParser implements LeafParser
{
    public function supports(NodeInterface $node): bool
    {
        return $node::class === IntegerNode::class;
    }

    public function parse(string $name, string $raw): int
    {
        $value = trim($raw);

        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw ConfigurationException::environmentValueInvalid($name, 'a whole number', $raw);
        }

        return (int) $value;
    }
}
