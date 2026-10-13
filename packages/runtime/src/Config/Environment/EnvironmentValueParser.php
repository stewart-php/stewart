<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Config\BooleanSpelling;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\ScalarNode;
use Symfony\Component\Config\Definition\VariableNode;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class EnvironmentValueParser
{
    private const array LEAF_NODE_CLASSES = [
        BooleanNode::class,
        IntegerNode::class,
        FloatNode::class,
        ScalarNode::class,
        EnumNode::class,
        VariableNode::class,
    ];

    public function supportsNode(NodeInterface $node): bool
    {
        return \in_array($node::class, self::LEAF_NODE_CLASSES, true) || $this->isScalarList($node);
    }

    /** @throws ConfigurationException */
    public function parseForNode(NodeInterface $node, string $name, string $raw): mixed
    {
        return match ($node::class) {
            BooleanNode::class => BooleanSpelling::tryParseBoolean($raw) ?? throw $this->createValueInvalidFailure($name, 'true or false', $raw),
            IntegerNode::class => $this->parseInteger($name, $raw),
            FloatNode::class => $this->parseFloat($name, $raw),
            VariableNode::class => $this->inferValue($raw),
            default => $this->isScalarList($node) ? $this->parseInlineList($name, $raw) : $raw,
        };
    }

    /** @throws ConfigurationException */
    private function parseInteger(string $name, string $raw): int
    {
        $value = trim($raw);

        return preg_match('/^[+-]?\d+$/', $value) === 1 ? (int) $value : throw $this->createValueInvalidFailure($name, 'a whole number', $raw);
    }

    /** @throws ConfigurationException */
    private function parseFloat(string $name, string $raw): float
    {
        $value = trim($raw);

        return is_numeric($value) ? (float) $value : throw $this->createValueInvalidFailure($name, 'a number', $raw);
    }

    /**
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    private function parseInlineList(string $name, string $raw): array
    {
        if (!$this->isInlineCollection($raw)) {
            throw $this->createValueInvalidFailure($name, 'an inline list such as [a, b]', $raw);
        }

        try {
            $value = Yaml::parse(trim($raw));
        } catch (ParseException $e) {
            throw ConfigurationException::createForReason(ConfigurationError::EnvironmentValueUnparsable, $e, variable: $name);
        }

        return \is_array($value) ? $value : throw $this->createValueInvalidFailure($name, 'an inline list such as [a, b]', $raw);
    }

    private function inferValue(string $raw): mixed
    {
        $value = trim($raw);

        if ($this->isInlineCollection($value)) {
            try {
                return Yaml::parse($value);
            } catch (ParseException) {
                return $value;
            }
        }

        return match (true) {
            strtolower($value) === 'true' => true,
            strtolower($value) === 'false' => false,
            strtolower($value) === 'null', $value === '~' => null,
            $value === (string) (int) $value => (int) $value,
            is_numeric($value) && $value === (string) (float) $value => (float) $value,
            default => $value,
        };
    }

    private function isScalarList(NodeInterface $node): bool
    {
        return $node instanceof PrototypedArrayNode && $node->getPrototype() instanceof ScalarNode;
    }

    private function isInlineCollection(string $raw): bool
    {
        $value = trim($raw);

        return str_starts_with($value, '[') || str_starts_with($value, '{');
    }

    private function createValueInvalidFailure(string $name, string $expected, string $raw): ConfigurationException
    {
        return ConfigurationException::createForReason(ConfigurationError::EnvironmentValueInvalid, variable: $name, expected: $expected, raw: $raw);
    }
}
