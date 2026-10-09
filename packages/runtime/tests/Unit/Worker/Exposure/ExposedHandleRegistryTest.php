<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Runtime\Ipc\Message\ExposuresReleased;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingExposedHandle;
use Stewart\Runtime\Worker\Exposure\ExposedHandleRegistry;

#[CoversClass(ExposedHandleRegistry::class)]
final class ExposedHandleRegistryTest extends TestCase
{
    private NullTransport $transport;

    private ExposedHandleRegistry $registry;

    protected function setUp(): void
    {
        $this->transport = new NullTransport();
        $this->registry = new ExposedHandleRegistry($this->transport, new NullLogger());
    }

    public function testSnapshotReachesItsHandle(): void
    {
        $handle = new RecordingExposedHandle();
        $this->registry->recordHandle(self::createScope('demo'), new ExposedEntityKey('level'), $handle);
        $snapshot = new ExposedEntitySnapshot(new EntityId('sensor.demo_level'), new ExposedState(3), [], true);

        $this->registry->applySnapshot(self::createScope('demo'), new ExposedEntityKey('level'), $snapshot);
        $this->registry->applySnapshot(self::createScope('other'), new ExposedEntityKey('level'), $snapshot);

        self::assertSame([$snapshot], $handle->snapshots);
    }

    public function testReleasingScopeTellsBrokerOnce(): void
    {
        $handle = new RecordingExposedHandle();
        $this->registry->recordHandle(self::createScope('demo'), new ExposedEntityKey('level'), $handle);

        $this->registry->releaseHandlesOf(self::createScope('demo'));
        $this->registry->releaseHandlesOf(self::createScope('demo'));

        self::assertTrue($handle->released);
        self::assertFalse($this->registry->isKeyTaken(self::createScope('demo'), new ExposedEntityKey('level')));
        self::assertEquals([new ExposuresReleased(self::createScope('demo'))], $this->transport->sent);
    }

    public function testReleasingAllStaysLocal(): void
    {
        $handle = new RecordingExposedHandle();
        $this->registry->recordHandle(self::createScope('demo'), new ExposedEntityKey('level'), $handle);

        $this->registry->releaseAll();

        self::assertTrue($handle->released);
        self::assertSame([], $this->transport->sent);
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }
}
