<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\NodeInterface;

interface LeafParser
{
    public function supports(NodeInterface $node): bool;

    /** @throws ConfigurationException */
    public function parse(string $name, string $raw): mixed;
}
