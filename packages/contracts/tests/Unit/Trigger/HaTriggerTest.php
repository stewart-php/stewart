<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Trigger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Exception\TriggerError;
use Stewart\Contracts\Exception\TriggerException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerJson;
use Stewart\Contracts\Trigger\ZoneTransition;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(HaTrigger::class)]
#[CoversClass(TriggerJson::class)]
#[CoversClass(TriggerException::class)]
final class HaTriggerTest extends TestCase
{
    use AssertsReason;

    /** @param array<string, mixed> $expected */
    #[DataProvider('provideBuiltTriggers')]
    public function testBuilderEmitsHaConfig(HaTrigger $trigger, array $expected): void
    {
        self::assertSame($expected, $trigger->toArray());
    }

    /** @return iterable<string, array{HaTrigger, array<string, mixed>}> */
    public static function provideBuiltTriggers(): iterable
    {
        yield 'sunrise' => [HaTrigger::onSunrise(), ['trigger' => 'sun', 'event' => 'sunrise']];
        yield 'sunset before' => [
            HaTrigger::onSunset(SunOffset::before(Duration::minutes(30)), 'dusk'),
            ['trigger' => 'sun', 'event' => 'sunset', 'offset' => '-00:30:00', 'id' => 'dusk'],
        ];
        yield 'sunrise zero offset' => [HaTrigger::onSunrise(SunOffset::none()), ['trigger' => 'sun', 'event' => 'sunrise']];
        yield 'time of day' => [HaTrigger::atTime('07:30'), ['trigger' => 'time', 'at' => '07:30']];
        yield 'time with seconds' => [HaTrigger::atTime('23:59:59'), ['trigger' => 'time', 'at' => '23:59:59']];
        yield 'input_datetime string' => [
            HaTrigger::atTime('input_datetime.wake_up'),
            ['trigger' => 'time', 'at' => 'input_datetime.wake_up'],
        ];
        yield 'timestamp sensor id' => [
            HaTrigger::atTime(new EntityId('sensor.next_alarm'), 'alarm'),
            ['trigger' => 'time', 'at' => 'sensor.next_alarm', 'id' => 'alarm'],
        ];
        yield 'time pattern' => [
            HaTrigger::onTimePattern(minutes: '/5'),
            ['trigger' => 'time_pattern', 'minutes' => '/5'],
        ];
        yield 'time pattern all parts' => [
            HaTrigger::onTimePattern(1, '*', 0),
            ['trigger' => 'time_pattern', 'hours' => 1, 'minutes' => '*', 'seconds' => 0],
        ];
        yield 'template' => [
            HaTrigger::whenTemplateTrue('{{ true }}'),
            ['trigger' => 'template', 'value_template' => '{{ true }}'],
        ];
        yield 'template held' => [
            HaTrigger::whenTemplateTrue('{{ true }}', Duration::seconds(90.5)),
            ['trigger' => 'template', 'value_template' => '{{ true }}', 'for' => '00:01:30.5'],
        ];
        yield 'zone' => [
            HaTrigger::onZoneTransition('person.anna', 'zone.home', ZoneTransition::Leave, 'away'),
            ['trigger' => 'zone', 'entity_id' => 'person.anna', 'zone' => 'zone.home', 'event' => 'leave', 'id' => 'away'],
        ];
    }

    public function testFromArrayKeepsConfigAsGiven(): void
    {
        $config = ['platform' => 'state', 'entity_id' => ['light.hall'], 'to' => 'on'];

        $trigger = HaTrigger::fromArray($config);

        self::assertSame($config, $trigger->toArray());
        self::assertSame('state', $trigger->getPlatform());
        self::assertSame('sun', HaTrigger::fromArray(['trigger' => 'sun'])->getPlatform());
    }

    /** @param array<array-key, mixed> $config */
    #[DataProvider('provideInvalidConfigs')]
    public function testFromArrayRejectsInvalidConfig(array $config, TriggerError $reason): void
    {
        $this->assertThrowsReason($reason, static fn(): HaTrigger => HaTrigger::fromArray($config));
    }

    /** @return iterable<string, array{array<array-key, mixed>, TriggerError}> */
    public static function provideInvalidConfigs(): iterable
    {
        yield 'no platform' => [['entity_id' => 'light.hall'], TriggerError::ConfigInvalid];
        yield 'empty platform' => [['trigger' => ''], TriggerError::ConfigInvalid];
        yield 'list' => [[['trigger' => 'sun']], TriggerError::ConfigInvalid];
        yield 'numeric key' => [['trigger' => 'sun', 5 => 'x'], TriggerError::ConfigInvalid];
        yield 'infinite float' => [['trigger' => 'numeric_state', 'above' => \INF], TriggerError::ConfigUnencodable];
        yield 'object' => [['trigger' => 'sun', 'nested' => ['at' => new stdClass()]], TriggerError::ConfigUnencodable];
        yield 'invalid utf-8' => [['trigger' => 'sun', 'event' => "\xff"], TriggerError::ConfigUnencodable];
    }

    public function testUnencodablePathIsNamed(): void
    {
        $exception = $this->assertThrowsReason(
            TriggerError::ConfigUnencodable,
            static fn(): HaTrigger => HaTrigger::fromArray(['trigger' => 'sun', 'nested' => ['at' => \NAN]]),
        );

        self::assertSame('trigger.nested.at', $exception->context['path'] ?? null);
    }

    #[DataProvider('provideInvalidTimes')]
    public function testAtTimeRejectsInvalidTime(string $time): void
    {
        $this->assertThrowsReason(TriggerError::TimeInvalid, static fn(): HaTrigger => HaTrigger::atTime($time));
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidTimes(): iterable
    {
        yield 'hour out of range' => ['24:00'];
        yield 'no minutes' => ['7'];
        yield 'light entity' => ['light.hall'];
        yield 'garbage' => ['soon'];
    }

    public function testTimePatternNeedsOnePart(): void
    {
        $this->assertThrowsReason(TriggerError::TimePatternEmpty, static fn(): HaTrigger => HaTrigger::onTimePattern());
    }

    public function testTemplateMustNotBeBlank(): void
    {
        $this->assertThrowsReason(TriggerError::TemplateEmpty, static fn(): HaTrigger => HaTrigger::whenTemplateTrue('  '));
    }

    public function testZoneMustBeZoneEntity(): void
    {
        $this->assertThrowsReason(
            TriggerError::ZoneInvalid,
            static fn(): HaTrigger => HaTrigger::onZoneTransition('person.anna', 'person.bob', ZoneTransition::Enter),
        );
    }

    public function testZoneEntityMustBeValidId(): void
    {
        $this->assertThrowsReason(
            IdentifierError::EntityIdInvalid,
            static fn(): HaTrigger => HaTrigger::onZoneTransition('anna', 'zone.home', ZoneTransition::Enter),
        );
    }
}
