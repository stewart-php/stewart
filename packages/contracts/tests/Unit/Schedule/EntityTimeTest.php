<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Schedule;

use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ScheduleError;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\EntityTime;
use Stewart\Contracts\Schedule\EntityTimeKind;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Instant;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(EntityTime::class)]
#[CoversClass(ScheduleException::class)]
final class EntityTimeTest extends TestCase
{
    use AssertsReason;

    private const string ZONE = 'Europe/Budapest';

    public function testDateAndTimeInputIsMomentInZone(): void
    {
        $time = $this->parse('input_datetime.wake_up', '2026-06-01 07:30:00', ['has_date' => true, 'has_time' => true]);

        self::assertSame(EntityTimeKind::Moment, $time->kind);
        self::assertSame('2026-06-01T05:30:00.000000Z', $time->moment?->toIso8601());
    }

    public function testTimeOnlyInputIsDaily(): void
    {
        $time = $this->parse('input_datetime.wake_up', '07:30:00', ['has_date' => false, 'has_time' => true]);

        self::assertSame(EntityTimeKind::Daily, $time->kind);
        self::assertSame('07:30:00', $time->dailyAt?->format());
    }

    public function testDateOnlyInputHasNoTime(): void
    {
        $time = $this->parse('input_datetime.holiday', '2026-06-01', ['has_date' => true, 'has_time' => false]);

        self::assertSame(EntityTimeKind::None, $time->kind);
    }

    /** @return iterable<string, array{string, EntityTimeKind}> */
    public static function provideFlaglessInputs(): iterable
    {
        yield 'date and time' => ['2026-06-01 07:30:00', EntityTimeKind::Moment];
        yield 'time only' => ['07:30:00', EntityTimeKind::Daily];
        yield 'date only' => ['2026-06-01', EntityTimeKind::None];
    }

    #[DataProvider('provideFlaglessInputs')]
    public function testInputWithoutFlagsFallsBackToShape(string $value, EntityTimeKind $expected): void
    {
        self::assertSame($expected, $this->parse('input_datetime.wake_up', $value)->kind);
    }

    public function testTimestampSensorIsMoment(): void
    {
        $time = $this->parse('sensor.next_alarm', '2026-06-01T05:30:00+00:00', ['device_class' => 'timestamp']);

        self::assertSame(EntityTimeKind::Moment, $time->kind);
        self::assertSame('2026-06-01T05:30:00.000000Z', $time->moment?->toIso8601());
    }

    public function testSensorWithoutTimestampClassHasNoTime(): void
    {
        self::assertSame(EntityTimeKind::None, $this->parse('sensor.temperature', '21.5', ['device_class' => 'temperature'])->kind);
    }

    public function testUnavailableOrMissingStateHasNoTime(): void
    {
        self::assertSame(EntityTimeKind::None, $this->parse('input_datetime.wake_up', EntityState::UNAVAILABLE)->kind);
        self::assertSame(EntityTimeKind::None, EntityTime::fromEntityState(null, new DateTimeZone(self::ZONE))->kind);
    }

    public function testEqualsComparesKindAndValue(): void
    {
        $morning = EntityTime::forDailyTime(TimeOfDay::parse('07:30'));

        self::assertTrue($morning->equals(EntityTime::forDailyTime(TimeOfDay::parse('07:30:00'))));
        self::assertFalse($morning->equals(EntityTime::forDailyTime(TimeOfDay::parse('08:00'))));
        self::assertFalse($morning->equals(EntityTime::none()));
        self::assertTrue(EntityTime::forMoment(Instant::fromIso('2026-06-01T05:30:00Z'))->equals(EntityTime::forMoment(Instant::fromIso('2026-06-01T07:30:00+02:00'))));
    }

    public function testUnsupportedDomainThrows(): void
    {
        EntityTime::requireSupportedEntity(new EntityId('sensor.next_alarm'));

        $this->assertThrowsReason(ScheduleError::EntityTimeDomainUnsupported, static fn() => EntityTime::requireSupportedEntity(new EntityId('light.hall')));
    }

    /** @param array<string, mixed> $attributes */
    private function parse(string $entityId, string $state, array $attributes = []): EntityTime
    {
        return EntityTime::fromEntityState(new EntityState(new EntityId($entityId), $state, $attributes), new DateTimeZone(self::ZONE));
    }
}
