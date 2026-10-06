<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Runtime\Ipc\Message\RegistrySnapshot;
use Stewart\Runtime\Ipc\Wire\RegistryFragment;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Worker\Message\RegistrySnapshotHandler;

#[CoversClass(RegistrySnapshotHandler::class)]
final class RegistrySnapshotHandlerTest extends TestCase
{
    public function testSnapshotSeedsWorkerRegistry(): void
    {
        $cache = new RegistryCache();
        $handler = new RegistrySnapshotHandler($cache, new NullLogger());

        $handler->handle(new RegistrySnapshot(self::createFragment('Kitchen'), 4));
        $handler->handle(new RegistrySnapshot(self::createFragment('Stale kitchen'), 3));

        self::assertSame(4, $cache->getRevision());
        self::assertSame('Kitchen', $cache->findArea('kitchen')?->name);
    }

    private static function createFragment(string $areaName): RegistryFragment
    {
        return RegistryFragment::fromRegistry(IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId([new Area(new AreaId('kitchen'), $areaName)]),
            FloorCollection::empty(),
            LabelCollection::empty(),
            DeviceCollection::empty(),
            RegisteredEntityCollection::empty(),
        ));
    }
}
