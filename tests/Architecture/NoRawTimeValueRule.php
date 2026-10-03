<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node> */
final class NoRawTimeValueRule implements Rule
{
    private const string FRAMEWORK_SOURCE = '#/packages/(?!testing/)[^/]+/src/#';

    private const string TIME_NAME = '/[a-z](At|Ms|Seconds|Nanos)$/';

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (preg_match(self::FRAMEWORK_SOURCE, $scope->getFile()) !== 1) {
            return [];
        }

        $named = match (true) {
            $node instanceof Param && $node->var instanceof Node\Expr\Variable && \is_string($node->var->name) => [[$node->var->name, $node->type]],
            $node instanceof Property => array_map(static fn(Node\PropertyItem $item): array => [$item->name->toString(), $node->type], $node->props),
            default => [],
        };

        $errors = [];

        foreach ($named as [$name, $type]) {
            if (preg_match(self::TIME_NAME, $name) === 1 && self::isNumeric($type)) {
                $errors[] = RuleErrorBuilder::message(\sprintf(
                    '$%s is a raw number named like a time; use Instant, MonotonicTime or Duration.',
                    $name,
                ))->identifier('stewart.rawTimeValue')->build();
            }
        }

        return $errors;
    }

    private static function isNumeric(?Node $type): bool
    {
        return match (true) {
            $type instanceof Identifier => \in_array($type->toLowerString(), ['int', 'float'], true),
            $type instanceof NullableType => self::isNumeric($type->type),
            $type instanceof UnionType => array_filter($type->types, self::isNumeric(...)) !== [],
            default => false,
        };
    }
}
