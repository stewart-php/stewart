<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Config\Environment\LeafParserChain;
use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\VariableNode;

final readonly class EnvironmentPlaceholders
{
    private const string PLACEHOLDER = '/\$\$\{|\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/';

    private const string WHOLE_VALUE_PLACEHOLDER = '/\A\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-[^}]*)?\}\z/';

    public function __construct(
        private EnvironmentVariables $variables,
        private LeafParserChain $leafParserChain,
    ) {}

    /**
     * @param array<array-key, mixed> $file
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    public function resolve(NodeInterface $tree, array $file): array
    {
        return $this->substituteInTree($file, $tree, '');
    }

    /**
     * @param array<array-key, mixed> $file
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    private function substituteInTree(array $file, ?NodeInterface $node, string $path): array
    {
        foreach ($file as $key => $value) {
            $setting = $path === '' ? (string) $key : $path . '.' . $key;
            $child = $this->findChildNode($node, (string) $key);

            $file[$key] = match (true) {
                \is_array($value) => $this->substituteInTree($value, $child, $setting),
                \is_string($value) => $this->resolveValue($value, $child, $setting),
                default => $value,
            };
        }

        return $file;
    }

    private function findChildNode(?NodeInterface $node, string $key): ?NodeInterface
    {
        return match (true) {
            $node instanceof PrototypedArrayNode => $node->getPrototype(),
            $node instanceof ArrayNode => $node->getChildren()[$key] ?? $node->getChildren()[strtr($key, '-', '_')] ?? null,
            // ScalarNode extends VariableNode, so only a free-form node passes its type on to nested values.
            $node !== null && $node::class === VariableNode::class => $node,
            default => null,
        };
    }

    /** @throws ConfigurationException */
    private function resolveValue(string $value, ?NodeInterface $node, string $setting): mixed
    {
        $substituted = $this->substitute($value, $setting);

        if ($node === null || preg_match(self::WHOLE_VALUE_PLACEHOLDER, $value, $match) !== 1) {
            return $substituted;
        }

        return $this->leafParserChain->parseForNode($node, \sprintf('%s (${%s})', $setting, $match[1]), $substituted);
    }

    /** @throws ConfigurationException */
    private function substitute(string $value, string $setting): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER,
            function (array $match) use ($setting): string {
                $name = $match[1] ?? null;

                if ($name === null) {
                    return '${';
                }

                $variable = $this->variables->get($name);
                $default = $match[2] ?? null;

                if ($default !== null && ($variable === null || $variable === '')) {
                    return $default;
                }

                return $variable ?? throw ConfigurationException::placeholderUnset($setting, $name);
            },
            $value,
        ) ?? $value;
    }
}
