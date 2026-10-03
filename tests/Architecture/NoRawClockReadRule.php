<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node\Expr> */
final class NoRawClockReadRule implements Rule
{
    private const string FRAMEWORK_SOURCE = '#/packages/(?!testing/)[^/]+/src/#';

    private const string CLOCK_IMPLEMENTATIONS = '#/packages/runtime/src/Time/#';

    private const array CLOCK_FUNCTIONS = ['microtime', 'hrtime', 'time'];

    private const array DATE_CLASSES = ['DateTime', 'DateTimeImmutable'];

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();

        if (preg_match(self::FRAMEWORK_SOURCE, $file) !== 1 || preg_match(self::CLOCK_IMPLEMENTATIONS, $file) === 1) {
            return [];
        }

        $read = match (true) {
            $node instanceof FuncCall => self::findClockFunctionName($node),
            $node instanceof New_ => self::findCurrentDateClassName($node),
            default => null,
        };

        if ($read === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                '%s reads the clock directly; take an injected Clock or Timers and work in Instant, MonotonicTime and Duration.',
                $read,
            ))->identifier('stewart.rawClockRead')->build(),
        ];
    }

    private static function findClockFunctionName(FuncCall $call): ?string
    {
        if (!$call->name instanceof Name) {
            return null;
        }

        $name = strtolower($call->name->toString());

        return \in_array($name, self::CLOCK_FUNCTIONS, true) ? $name . '()' : null;
    }

    private static function findCurrentDateClassName(New_ $new): ?string
    {
        if (!$new->class instanceof Name || !\in_array($new->class->toString(), self::DATE_CLASSES, true)) {
            return null;
        }

        $first = $new->getArgs()[0]->value ?? null;

        if ($first === null || ($first instanceof String_ && strtolower(trim($first->value)) === 'now')) {
            return 'new ' . $new->class->toString() . '()';
        }

        return null;
    }
}
