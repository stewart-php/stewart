<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Worker\ExposureBrokerStub;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\Exposure\ExposedHandleRegistry;
use Stewart\Runtime\Worker\Exposure\ExposureRequester;
use Stewart\Runtime\Worker\Exposure\WorkerExposedBinarySensor;
use Stewart\Runtime\Worker\Exposure\WorkerExposedEntity;
use Stewart\Runtime\Worker\Exposure\WorkerExposedSensor;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(WorkerEntityExposure::class)]
#[CoversClass(WorkerExposedEntity::class)]
#[CoversClass(WorkerExposedSensor::class)]
#[CoversClass(WorkerExposedBinarySensor::class)]
final class WorkerEntityExposureTest extends TestCase
{
    use AssertsReason;

    private ExposureBrokerStub $broker;

    private ExposedHandleRegistry $handles;

    private WorkerEntityExposure $shared;

    protected function setUp(): void
    {
        $pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
        $this->broker = new ExposureBrokerStub($pending);
        $this->handles = new ExposedHandleRegistry($this->broker, new NullLogger());
        $this->shared = new WorkerEntityExposure(
            new ExposureRequester($this->broker, $pending, new ManualTimers(), Duration::seconds(1)),
            $this->handles,
            ResourceScope::shared(),
        );
    }

    public function testSnapshotSeedsTheHandle(): void
    {
        $this->broker->snapshot = new ExposedEntitySnapshot(new EntityId('sensor.climate_level'), new ExposedState(21.4), ['source' => 'hall'], true);

        $sensor = $this->createClimateExposure()->exposeSensor('level');

        self::assertSame('sensor.climate_level', $sensor->getEntityId()?->value);
        self::assertSame(21.4, $sensor->getValue());
        self::assertSame(['source' => 'hall'], $sensor->getAttributes());
    }

    public function testPendingHandleGetsEntityIdOnSync(): void
    {
        $sensor = $this->createClimateExposure()->exposeSensor('level');

        $this->handles->applySnapshot(
            ResourceScope::forApp(new AppId('climate')),
            new ExposedEntityKey('level'),
            new ExposedEntitySnapshot(new EntityId('sensor.climate_level'), new ExposedState(19), [], false),
        );

        self::assertSame('sensor.climate_level', $sensor->getEntityId()?->value);
        self::assertSame(19, $sensor->getValue());
        self::assertFalse($sensor->isAvailable());
    }

    public function testTimestampValueIsSentAsIsoString(): void
    {
        $sensor = $this->createClimateExposure()->exposeSensor('last_run', new SensorConfig(SensorDeviceClass::Timestamp));

        $sensor->setValue(new DateTimeImmutable('2026-10-09 07:30:00+02:00'), ['run' => 3]);

        $update = $this->broker->listSentOfType(UpdateExposedEntityRequest::class)[0];
        self::assertSame('2026-10-09T07:30:00+02:00', $update->change->state?->value);
        self::assertSame('2026-10-09T07:30:00+02:00', $sensor->getValue());
        self::assertSame(['run' => 3], $sensor->getAttributes());
    }

    public function testBinarySensorSendsBooleans(): void
    {
        $presence = $this->createClimateExposure()->exposeBinarySensor('anyone_home');

        $presence->setOn();
        $presence->markUnavailable();

        self::assertTrue($presence->getValue());
        self::assertFalse($presence->isAvailable());
        self::assertFalse($this->broker->listSentOfType(UpdateExposedEntityRequest::class)[1]->change->available);
    }

    public function testSameKeyTwiceIsTaken(): void
    {
        $exposure = $this->createClimateExposure();
        $exposure->exposeSensor('level');

        $this->assertThrowsReason(ExposureError::KeyTaken, static fn() => $exposure->exposeBinarySensor('level'));
    }

    public function testRefusedExposureFreesItsKey(): void
    {
        $exposure = $this->createClimateExposure();
        $this->broker->failure = ExposureException::componentMissing(new ExposedEntityKey('level'));

        $this->assertThrowsReason(ExposureError::ComponentMissing, static fn() => $exposure->exposeSensor('level'));
        $this->broker->failure = null;

        self::assertSame('level', $exposure->exposeSensor('level')->getKey()->value);
    }

    public function testSharedScopeIsOutsideApp(): void
    {
        $this->assertThrowsReason(ExposureError::OutsideApp, fn() => $this->shared->exposeSensor('level'));
    }

    public function testRemovedHandleRefusesUpdates(): void
    {
        $exposure = $this->createClimateExposure();
        $sensor = $exposure->exposeSensor('level');

        $sensor->remove();

        self::assertCount(1, $this->broker->listSentOfType(RemoveExposedEntityRequest::class));
        $this->assertThrowsReason(ExposureError::Removed, static fn() => $sensor->setValue(3));
        self::assertSame('level', $exposure->exposeSensor('level')->getKey()->value);
    }

    public function testReleasedScopeRefusesUpdates(): void
    {
        $sensor = $this->createClimateExposure()->exposeSensor('level');

        $this->handles->releaseHandlesOf(ResourceScope::forApp(new AppId('climate')));

        $this->assertThrowsReason(ExposureError::Removed, static fn() => $sensor->setValue(3));
    }

    private function createClimateExposure(): WorkerEntityExposure
    {
        return $this->shared->forApp(new AppId('climate'));
    }
}
