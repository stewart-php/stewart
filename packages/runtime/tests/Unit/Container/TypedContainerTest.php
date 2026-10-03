<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Stewart\Runtime\Container\TypedContainer;
use Stewart\Runtime\Exception\ContainerError;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Testing\Exception\AssertsReason;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(TypedContainer::class)]
final class TypedContainerTest extends TestCase
{
    use AssertsReason;

    public function testReturnsServiceOfAskedType(): void
    {
        $services = new TypedContainer(self::createContainerHolding('stewart.thing', new NullTransport()));

        self::assertInstanceOf(NullTransport::class, $services->resolveService(NullTransport::class, 'stewart.thing'));
    }

    public function testAskingForTheWrongTypeSaysWhichServiceDisagreed(): void
    {
        $services = new TypedContainer(self::createContainerHolding('stewart.thing', new NullTransport()));

        $e = $this->assertThrowsReason(ContainerError::ServiceTypeMismatch, fn() => $services->resolveService(TestCase::class, 'stewart.thing'));

        self::assertMatchesRegularExpression('/"stewart.thing" is .*NullTransport; expected .*TestCase/', $e->getMessage());
    }

    public function testAbsentServiceIsNotFound(): void
    {
        $services = new TypedContainer(self::createContainerHolding('stewart.thing', new NullTransport()));

        $this->expectException(NotFoundExceptionInterface::class);

        $services->resolveService(NullTransport::class, 'stewart.absent');
    }

    private static function createContainerHolding(string $id, object $service): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register($id)->setSynthetic(true)->setPublic(true);
        $container->compile();
        $container->set($id, $service);

        return $container;
    }
}
