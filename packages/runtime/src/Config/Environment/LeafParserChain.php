<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\NodeInterface;

final readonly class LeafParserChain
{
    /** @param iterable<LeafParser> $leafParsers */
    public function __construct(private iterable $leafParsers) {}

    public function supportsNode(NodeInterface $node): bool
    {
        return $this->findParserFor($node) !== null;
    }

    /** @throws ConfigurationException */
    public function parseForNode(NodeInterface $node, string $label, string $raw): mixed
    {
        $parser = $this->findParserFor($node);

        return $parser === null ? $raw : $parser->parse($label, $raw);
    }

    private function findParserFor(NodeInterface $node): ?LeafParser
    {
        foreach ($this->leafParsers as $parser) {
            if ($parser->supports($node)) {
                return $parser;
            }
        }

        return null;
    }
}
