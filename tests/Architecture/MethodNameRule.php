<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<InClassMethodNode> */
final class MethodNameRule implements Rule
{
    private const string FRAMEWORK_SOURCE = '#/packages/[^/]+/src/#';

    private const string STEWART_NAMESPACE = 'Stewart\\';

    private const array BANNED_NAMES = ['of', 'in', 'on', 'at', 'build', 'create', 'decode'];

    private const string COPY_PREFIX = '/^with[A-Z]/';

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $name = $node->getMethodReflection()->getName();

        if (preg_match(self::FRAMEWORK_SOURCE, $scope->getFile()) !== 1 || $this->isFixedByForeignAncestor($class, $name)) {
            return [];
        }

        if (\in_array(strtolower($name), self::BANNED_NAMES, true)) {
            return [
                RuleErrorBuilder::message(\sprintf('%s() says nothing about what happens; name it with a verb phrase.', $name))
                    ->identifier('stewart.vagueMethodName')
                    ->build(),
            ];
        }

        if (preg_match(self::COPY_PREFIX, $name) === 1 && !$class->isReadOnly() && !$class->isEnum() && !$class->isInterface()) {
            return [
                RuleErrorBuilder::message(\sprintf('%s() is named as an immutable copy, but %s is mutable.', $name, $class->getName()))
                    ->identifier('stewart.mutableWithMethod')
                    ->build(),
            ];
        }

        return [];
    }

    private function isFixedByForeignAncestor(ClassReflection $class, string $name): bool
    {
        foreach ([...$class->getParents(), ...array_values($class->getInterfaces())] as $ancestor) {
            if (!str_starts_with($ancestor->getName(), self::STEWART_NAMESPACE) && $ancestor->hasNativeMethod($name)) {
                return true;
            }
        }

        return false;
    }
}
