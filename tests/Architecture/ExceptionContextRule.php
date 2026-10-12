<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;

/** @implements Rule<StaticCall> */
final readonly class ExceptionContextRule implements Rule
{
    private const string CREATE_METHOD = 'createForReason';

    private const array NON_CONTEXT_PARAMETERS = ['exceptionReason', 'previous'];

    private const string CAUSE_PLACEHOLDER = 'cause';

    private const string PLACEHOLDER = '/\{(\w+)\}/';

    public function __construct(private ReflectionProvider $reflection) {}

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== self::CREATE_METHOD || !$node->class instanceof Name) {
            return [];
        }

        $exceptionClass = $scope->resolveName($node->class);

        if (!$this->reflection->hasClass($exceptionClass) || !$this->reflection->getClass($exceptionClass)->isSubclassOfClass($this->reflection->getClass(StewartException::class))) {
            return [];
        }

        $reason = $node->getArgs()[0]->value ?? null;

        if (!$reason instanceof ClassConstFetch || !$reason->class instanceof Name || !$reason->name instanceof Identifier) {
            return $scope->getClassReflection()?->getName() === $exceptionClass
                ? []
                : [$this->buildError('stewart.exceptionReasonMismatch', 'Pass the reason to createForReason() as an enum case.')];
        }

        $reasonClass = $scope->resolveName($reason->class);
        $expectedReasonClass = (string) preg_replace('/Exception$/', 'Error', $exceptionClass);

        if ($reasonClass !== $expectedReasonClass) {
            return [$this->buildError('stewart.exceptionReasonMismatch', \sprintf('%s raises %s cases, not %s.', $exceptionClass, $expectedReasonClass, $reasonClass))];
        }

        $case = \constant($reasonClass . '::' . $reason->name->toString());

        return $case instanceof ExceptionReason ? $this->checkContext($node, $case) : [];
    }

    /** @return list<IdentifierRuleError> */
    private function checkContext(StaticCall $call, ExceptionReason $reason): array
    {
        preg_match_all(self::PLACEHOLDER, $reason->messageTemplate(), $matches);
        $placeholders = array_values(array_unique($matches[1]));
        $named = [];
        $hasPrevious = false;

        foreach (\array_slice($call->getArgs(), 1) as $position => $argument) {
            if ($argument->unpack) {
                return [];
            }

            $name = $argument->name?->toString();

            if ($name === null && $position === 0 || $name === 'previous') {
                $hasPrevious = true;
            } elseif ($name === null) {
                return [$this->buildError('stewart.exceptionContextMismatch', 'Pass the exception context as named arguments.')];
            } elseif (!\in_array($name, self::NON_CONTEXT_PARAMETERS, true)) {
                $named[] = $name;
            }
        }

        $errors = [];
        $expected = array_diff($placeholders, [self::CAUSE_PLACEHOLDER]);
        $missing = array_diff($expected, $named);

        if ($missing !== []) {
            $errors[] = $this->buildError('stewart.exceptionContextMismatch', \sprintf(
                '%s::%s renders [%s], which the context lacks.',
                $reason::class,
                $reason->name,
                implode(', ', $missing),
            ));
        }

        if (\in_array(self::CAUSE_PLACEHOLDER, $placeholders, true) && !$hasPrevious) {
            $errors[] = $this->buildError('stewart.exceptionContextMismatch', \sprintf('%s::%s renders {cause}; pass $previous.', $reason::class, $reason->name));
        }

        return $errors;
    }

    private function buildError(string $identifier, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier($identifier)->build();
    }
}
