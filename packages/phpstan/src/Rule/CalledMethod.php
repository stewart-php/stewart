<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Rule;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final readonly class CalledMethod
{
    /** @param list<Arg> $arguments */
    private function __construct(
        public Type $callerType,
        public string $method,
        private array $arguments,
    ) {}

    public static function findForCall(CallLike $call, Scope $scope): ?self
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $arguments = array_values($call->getArgs());

        if (($call instanceof MethodCall || $call instanceof NullsafeMethodCall) && $call->name instanceof Identifier) {
            return new self(TypeCombinator::removeNull($scope->getType($call->var)), $call->name->toString(), $arguments);
        }

        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier) {
            return new self(new ObjectType($scope->resolveName($call->class)), $call->name->toString(), $arguments);
        }

        if ($call instanceof New_ && $call->class instanceof Name) {
            return new self(new ObjectType($scope->resolveName($call->class)), '__construct', $arguments);
        }

        return null;
    }

    public function isCallTo(string $class, string $method): bool
    {
        return strcasecmp($this->method, $method) === 0 && new ObjectType($class)->isSuperTypeOf($this->callerType)->yes();
    }

    /** @return list<Expr> */
    public function listArgumentValues(int $position, string $name, bool $variadic = false): array
    {
        $values = [];

        foreach ($this->arguments as $index => $argument) {
            $matches = $argument->name === null
                ? $index === $position || ($variadic && $index > $position)
                : $argument->name->toString() === $name;

            if ($matches) {
                $values[] = $argument->value;
            }
        }

        return $values;
    }
}
