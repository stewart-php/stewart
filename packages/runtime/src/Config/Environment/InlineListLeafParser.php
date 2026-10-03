<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\ScalarNode;

final readonly class InlineListLeafParser implements LeafParser
{
    public function __construct(private InlineValueInference $inference) {}

    public function supports(NodeInterface $node): bool
    {
        return $node instanceof PrototypedArrayNode && $node->getPrototype() instanceof ScalarNode;
    }

    /** @return array<array-key, mixed> */
    public function parse(string $name, string $raw): array
    {
        $value = $this->inference->isInlineCollection($raw) ? $this->inference->parseInlineCollection($name, $raw) : null;

        return \is_array($value) ? $value : throw ConfigurationException::environmentValueInvalid($name, 'an inline list such as [a, b]', $raw);
    }
}
