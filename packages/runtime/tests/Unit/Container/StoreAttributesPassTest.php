<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\Container\AppContainerBuilder;
use Stewart\Runtime\Container\StoreAttributesPass;
use Stewart\Runtime\Exception\ContainerError;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsAttributedStores;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsInvalidPeerId;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsUnknownPeer;
use Stewart\Runtime\Tests\Fixtures\Container\NeedsWritablePeer;
use Stewart\Runtime\Tests\Fixtures\Container\SharedStoreConsumer;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Testing\Exception\AssertsReason;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(StoreAttributesPass::class)]
final class StoreAttributesPassTest extends TestCase
{
    use AssertsReason;

    public function testGlobalStoreAccessFollowsParameterType(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->autowire('keeper', NeedsAttributedStores::class);

        $this->createPass()->process($container);

        self::assertEquals(new Reference(AppContainerBuilder::GLOBAL_STORE), $definition->getArgument('$shared'));
        self::assertEquals(new Reference(AppContainerBuilder::READ_ONLY_GLOBAL_STORE), $definition->getArgument('$sharedView'));
    }

    public function testUnattributedParameterIsLeftToAutowiring(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->autowire('keeper', NeedsAttributedStores::class);

        $this->createPass()->process($container);

        self::assertArrayNotHasKey('$store', $definition->getArguments());
    }

    public function testPeerDefinitionIsShared(): void
    {
        $container = new ContainerBuilder();
        $first = $container->autowire('first', NeedsAttributedStores::class);
        $second = $container->autowire('second', NeedsAttributedStores::class);

        $this->createPass()->process($container);

        $peerId = AppContainerBuilder::buildPeerStoreServiceId(new AppId('boiler'));
        $peer = $container->getDefinition($peerId);

        self::assertEquals(new Reference($peerId), $first->getArgument('$peer'));
        self::assertEquals(new Reference($peerId), $second->getArgument('$peer'));
        self::assertEquals([new Reference(AppContainerBuilder::STORES), 'openPeerStoreForReading'], $peer->getFactory());
        self::assertEquals([new AppId('boiler')], $peer->getArguments());
    }

    public function testServiceOfTheUsersOwnIsFilledToo(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->autowire(SharedStoreConsumer::class, SharedStoreConsumer::class);

        $this->createPass()->process($container);

        self::assertEquals(new Reference(AppContainerBuilder::buildPeerStoreServiceId(new AppId('demo'))), $definition->getArgument('$demo'));
    }

    public function testArgumentAlreadySetIsKept(): void
    {
        $container = new ContainerBuilder();
        $named = $container->autowire('named', NeedsAttributedStores::class)->setArgument('$shared', new Reference('mine'));
        $positional = $container->autowire('positional', NeedsAttributedStores::class)->setArgument(1, new Reference('mine'));

        $this->createPass()->process($container);

        self::assertEquals(new Reference('mine'), $named->getArgument('$shared'));
        self::assertEquals(new Reference('mine'), $positional->getArgument(1));
        self::assertArrayNotHasKey('$shared', $positional->getArguments());
    }

    public function testDefinitionThatIsNotAutowiredIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $definition = $container->setDefinition('manual', new Definition(NeedsAttributedStores::class));

        $this->createPass()->process($container);

        self::assertSame([], $definition->getArguments());
    }

    public function testWritablePeerIsRefused(): void
    {
        $container = new ContainerBuilder();
        $container->autowire('keeper', NeedsWritablePeer::class);

        $this->assertThrowsReason(ContainerError::PeerStoreWritable, fn() => $this->createPass()->process($container));
    }

    public function testInvalidPeerAppIdIsRefused(): void
    {
        $container = new ContainerBuilder();
        $container->autowire('keeper', NeedsInvalidPeerId::class);

        $this->assertThrowsReason(ContainerError::PeerStoreAppIdInvalid, fn() => $this->createPass()->process($container));
    }

    public function testUnknownPeerFailsOnlyWhereItIsUsed(): void
    {
        $container = new ContainerBuilder();
        $container->autowire('keeper', NeedsUnknownPeer::class);

        $this->createPass()->process($container);

        $peer = $container->getDefinition(AppContainerBuilder::buildPeerStoreServiceId(new AppId('boilr')));

        $errors = $peer->getErrors();

        self::assertNull($peer->getFactory());
        self::assertCount(1, $errors);
        self::assertIsString($errors[0]);
        self::assertStringEndsWith('Did you mean "boiler"?', $errors[0]);
    }

    private function createPass(): StoreAttributesPass
    {
        return new StoreAttributesPass(AppIdCollection::fromList([new AppId('boiler'), new AppId('demo')]), new ClosestNameFinder());
    }
}
