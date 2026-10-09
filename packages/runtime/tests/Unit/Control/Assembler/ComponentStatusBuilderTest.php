<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Assembler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ComponentVersion;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Control\Assembler\ComponentStatusBuilder;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(ComponentStatusBuilder::class)]
final class ComponentStatusBuilderTest extends TestCase
{
    public function testStatusCarriesDetectionAndInstance(): void
    {
        $tracker = new ComponentTracker(new VirtualClock());
        $tracker->recordState(ComponentState::Active, new ComponentVersion('0.9.0', 1));

        $status = new ComponentStatusBuilder($tracker, new ExposeConfig(ComponentInstance::parse('upstairs'), Duration::seconds(10)))->buildComponentStatus();

        self::assertSame([ComponentState::Active, 'upstairs', '0.9.0', 1], [$status->state, $status->instance, $status->version, $status->protocol]);
    }

    public function testUncheckedComponentHasNoVersion(): void
    {
        $status = new ComponentStatusBuilder(new ComponentTracker(new VirtualClock()), new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)))->buildComponentStatus();

        self::assertSame(ComponentState::Unchecked, $status->state);
        self::assertNull($status->version);
    }
}
