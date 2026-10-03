<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;
use Psr\Log\InvalidArgumentException as PsrLogInvalidArgument;
use Stewart\Contracts\Exception\StewartException;

/** @implements Rule<Throw_> */
final class ThrowsStewartExceptionsRule implements Rule
{
    private const string FRAMEWORK_SOURCE = '#/packages/(?!testing/)[^/]+/(src|bin)/#';

    /** @var list<class-string> */
    private const array ALLOWED = [StewartException::class, PsrLogInvalidArgument::class, LogicException::class];

    public function getNodeType(): string
    {
        return Throw_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (preg_match(self::FRAMEWORK_SOURCE, $scope->getFile()) !== 1 || $node->expr instanceof Variable) {
            return [];
        }

        $thrown = $scope->getType($node->expr);

        foreach (self::ALLOWED as $allowed) {
            if (new ObjectType($allowed)->isSuperTypeOf($thrown)->yes()) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Throw a StewartException area class from a static factory, not %s.',
                $thrown->describe(VerbosityLevel::typeOnly()),
            ))->identifier('stewart.foreignThrow')->build(),
        ];
    }
}
