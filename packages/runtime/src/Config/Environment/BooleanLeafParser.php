<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Config\BooleanSpelling;
use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\NodeInterface;

final readonly class BooleanLeafParser implements LeafParser
{
    public function supports(NodeInterface $node): bool
    {
        return $node::class === BooleanNode::class;
    }

    public function parse(string $name, string $raw): bool
    {
        return BooleanSpelling::tryParseBoolean($raw) ?? throw ConfigurationException::environmentValueInvalid($name, 'true or false', $raw);
    }
}
