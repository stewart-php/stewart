<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;
use Throwable;
use UnitEnum;

#[CoversNothing]
final class ExceptionReasonsTest extends TestCase
{
    private const array NOT_FACTORIES = ['fromWire'];

    public function testEveryExceptionFileIsAnAreaClass(): void
    {
        $files = array_filter(
            new RepositoryFiles()->listFilesIn(['packages/*/src/Exception'], '*Exception.php'),
            static fn(string $file): bool => basename($file) !== 'StewartException.php',
        );
        $areaClasses = array_map(static fn(string $class): string => new ReflectionClass($class)->getShortName() . '.php', self::listAreaClasses());

        self::assertNotSame([], $areaClasses);
        self::assertSame(array_values(array_map(basename(...), $files)), $areaClasses);
    }

    public function testEveryFactoryRendersItsTemplate(): void
    {
        foreach (self::listAreaClasses() as $exception) {
            foreach (self::listFactoriesOf($exception) as $name => $factory) {
                foreach ([false, true] as $nullsWherePossible) {
                    $raised = $factory->invokeArgs(null, self::synthesizeArguments($factory, $nullsWherePossible));

                    self::assertInstanceOf($exception, $raised, \sprintf('%s::%s()', $exception, $name));
                }
            }
        }
    }

    public function testEveryAreaClassHasAStringBackedReasonEnum(): void
    {
        foreach (self::listAreaClasses() as $exception) {
            $reason = new ReflectionEnum(self::findReasonClassFor($exception));

            self::assertTrue($reason->implementsInterface(ExceptionReason::class), $reason->getName());
            self::assertSame('string', (string) $reason->getBackingType(), $reason->getName());
        }
    }

    public function testFactoriesAndReasonCasesMatchOneToOne(): void
    {
        foreach (self::listAreaClasses() as $exception) {
            $factories = array_keys(self::listFactoriesOf($exception));
            $cases = array_map(
                static fn(ExceptionReason $case): string => lcfirst($case->name),
                self::findReasonClassFor($exception)::cases(),
            );
            sort($factories);
            sort($cases);

            self::assertSame($cases, $factories, $exception);
        }
    }

    public function testEachFactoryRaisesItsOwnReason(): void
    {
        foreach (self::listAreaClasses() as $exception) {
            $enum = new ReflectionClass(self::findReasonClassFor($exception))->getShortName();

            foreach (self::listFactoriesOf($exception) as $name => $factory) {
                self::assertStringContainsString(
                    $enum . '::' . ucfirst($name),
                    self::readMethodSource($factory),
                    \sprintf('%s::%s()', $exception, $name),
                );
            }
        }
    }

    public function testEveryReasonHasAPlainMessageTemplate(): void
    {
        foreach (self::listAreaClasses() as $exception) {
            foreach (self::findReasonClassFor($exception)::cases() as $case) {
                $template = $case->messageTemplate();

                self::assertNotSame('', $template, $case::class . '::' . $case->name);
                self::assertStringNotContainsString('—', $template, $case::class . '::' . $case->name);
            }
        }
    }

    /** @return list<mixed> */
    private static function synthesizeArguments(ReflectionMethod $factory, bool $nullsWherePossible): array
    {
        $arguments = [];

        foreach ($factory->getParameters() as $parameter) {
            $type = $parameter->getType();
            \assert($type instanceof ReflectionNamedType, \sprintf('%s() has an untyped or union parameter.', $factory->getName()));

            $arguments[] = $nullsWherePossible && $type->allowsNull() ? null : self::synthesizeValue($type->getName());
        }

        return $arguments;
    }

    private static function synthesizeValue(string $type): mixed
    {
        return match (true) {
            $type === 'string', $type === 'mixed' => 'value',
            $type === 'int' => 1,
            $type === 'float' => 1.5,
            $type === 'bool' => true,
            $type === 'array' => ['value'],
            is_a($type, Throwable::class, true) => new RuntimeException('cause'),
            is_a($type, UnitEnum::class, true) => $type::cases()[0],
            $type === AppId::class => new AppId('demo'),
            $type === AppIdCollection::class => AppIdCollection::fromIds([new AppId('demo')]),
            $type === EntityId::class => new EntityId('light.hall'),
            $type === WorkerId::class => new WorkerId(1),
            $type === ExposedEntityKey::class => new ExposedEntityKey('demo'),
            $type === Duration::class => Duration::seconds(1),
            $type === Instant::class => Instant::fromEpochMicroseconds(0),
            default => throw new LogicException(\sprintf('No sample value for %s; add one here.', $type)),
        };
    }

    /** @return list<class-string<StewartException<ExceptionReason>>> */
    private static function listAreaClasses(): array
    {
        $classes = [];

        foreach (new RepositoryFiles()->listFilesIn(['packages/*/src/Exception'], '*Exception.php') as $file) {
            preg_match('/^namespace ([^;]+);/m', (string) file_get_contents($file), $namespace);
            $class = ($namespace[1] ?? '') . '\\' . basename($file, '.php');

            if (is_subclass_of($class, StewartException::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param class-string $exception
     * @return array<string, ReflectionMethod>
     */
    private static function listFactoriesOf(string $exception): array
    {
        $factories = [];

        foreach (new ReflectionClass($exception)->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            $returns = $method->getReturnType();

            $returnsSelf = $returns instanceof ReflectionNamedType && $returns->getName() === $exception;

            if ($method->isPublic() && $returnsSelf && !\in_array($method->getName(), self::NOT_FACTORIES, true)) {
                $factories[$method->getName()] = $method;
            }
        }

        return $factories;
    }

    private static function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName()) ?: [];

        return implode('', \array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    }

    /**
     * @param class-string<StewartException<ExceptionReason>> $exception
     * @return class-string<ExceptionReason>
     */
    private static function findReasonClassFor(string $exception): string
    {
        $reason = preg_replace('/Exception$/', 'Error', $exception);
        \assert(\is_string($reason) && is_subclass_of($reason, ExceptionReason::class));

        return $reason;
    }
}
