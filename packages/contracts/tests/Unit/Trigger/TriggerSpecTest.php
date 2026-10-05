<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Trigger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\TriggerError;
use Stewart\Contracts\Exception\TriggerException;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerJson;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(TriggerSpec::class)]
#[CoversClass(TriggerJson::class)]
#[CoversClass(TriggerException::class)]
final class TriggerSpecTest extends TestCase
{
    use AssertsReason;

    public function testSingleTriggerBecomesOneElementList(): void
    {
        $spec = TriggerSpec::fromSpec(HaTrigger::onSunset());

        self::assertSame([['trigger' => 'sun', 'event' => 'sunset']], $spec->listTriggerConfigs());
        self::assertSame(['sun'], $spec->listPlatforms());
    }

    public function testArrayMapIsOneTriggerAndListIsMany(): void
    {
        $single = TriggerSpec::fromSpec(['trigger' => 'sun', 'event' => 'sunset']);
        $many = TriggerSpec::fromSpec([['trigger' => 'sun', 'event' => 'sunset'], ['platform' => 'time', 'at' => '07:00']]);

        self::assertSame(1, $single->triggers->count());
        self::assertSame(['sun', 'time'], $many->listPlatforms());
    }

    public function testMapKeyOrderKeepsSharingKey(): void
    {
        $first = TriggerSpec::fromSpec(['trigger' => 'state', 'entity_id' => 'light.hall', 'to' => ['on', 'off']], ['b' => 1, 'a' => 2]);
        $second = TriggerSpec::fromSpec(['to' => ['on', 'off'], 'entity_id' => 'light.hall', 'trigger' => 'state'], ['a' => 2, 'b' => 1]);

        self::assertSame($first->getSharingKey(), $second->getSharingKey());
    }

    public function testListOrderChangesSharingKey(): void
    {
        $first = TriggerSpec::fromSpec(['trigger' => 'state', 'to' => ['on', 'off']]);
        $second = TriggerSpec::fromSpec(['trigger' => 'state', 'to' => ['off', 'on']]);

        self::assertNotSame($first->getSharingKey(), $second->getSharingKey());
    }

    public function testVariablesChangeSharingKeyButNotTriggerEquality(): void
    {
        $plain = TriggerSpec::fromSpec(HaTrigger::onSunset());
        $withVariables = TriggerSpec::fromSpec(HaTrigger::onSunset(), ['room' => 'hall']);

        self::assertNotSame($plain->getSharingKey(), $withVariables->getSharingKey());
        self::assertTrue($plain->hasSameTriggersAs($withVariables));
        self::assertFalse($plain->hasSameTriggersAs(TriggerSpec::fromSpec(HaTrigger::onSunrise())));
    }

    public function testEmptyTriggerListIsRejected(): void
    {
        $this->assertThrowsReason(TriggerError::ListEmpty, static fn(): TriggerSpec => TriggerSpec::fromSpec([]));
        $this->assertThrowsReason(
            TriggerError::ListEmpty,
            static fn(): TriggerSpec => TriggerSpec::fromSpec(HaTriggerCollection::empty()),
        );
    }

    public function testNonMapListElementIsRejected(): void
    {
        $this->assertThrowsReason(TriggerError::ConfigInvalid, static fn(): TriggerSpec => TriggerSpec::fromSpec(['sun']));
    }

    public function testVariablesMustBeMap(): void
    {
        $this->assertThrowsReason(
            TriggerError::VariablesNotMap,
            static fn(): TriggerSpec => TriggerSpec::fromSpec(HaTrigger::onSunset(), ['hall']),
        );
    }

    public function testUnencodableVariableIsRejected(): void
    {
        $this->assertThrowsReason(
            TriggerError::ConfigUnencodable,
            static fn(): TriggerSpec => TriggerSpec::fromSpec(HaTrigger::onSunset(), ['limit' => \INF]),
        );
    }
}
