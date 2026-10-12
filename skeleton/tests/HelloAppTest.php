<?php

declare(strict_types=1);

namespace App\Tests;

use App\HelloApp;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\State\EventContext;
use Stewart\Testing\App\AppHarness;

final class HelloAppTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('sun.sun', 'above_horizon');
        $this->harness->startApp(HelloApp::class, ['watch' => 'sun.sun']);
    }

    public function testEachChangeOfTheWatchedEntityIsCounted(): void
    {
        $this->harness->ha->pushState('sun.sun', 'below_horizon');
        $this->harness->ha->pushState('sun.sun', 'above_horizon');

        self::assertSame(2, $this->harness->entities->requireSensor('changes_seen')->getValue());
    }

    public function testStepSetsHowMuchAChangeCounts(): void
    {
        $this->harness->entities->requireNumber('step')->pushCommand(new NumberCommand(3, new EventContext('from-home-assistant')));

        $this->harness->ha->pushState('sun.sun', 'below_horizon');

        self::assertSame(3, $this->harness->entities->requireSensor('changes_seen')->getValue());
    }

    public function testTurningCountingOffStopsTheCount(): void
    {
        $this->harness->entities->requireSwitch('counting')->pushCommand(new SwitchCommand(SwitchAction::TurnOff, new EventContext('from-home-assistant')));

        $this->harness->ha->pushState('sun.sun', 'below_horizon');

        self::assertSame(0, $this->harness->entities->requireSensor('changes_seen')->getValue());
        self::assertSame('mdi:pause-circle', $this->harness->entities->requireNumber('step')->config->icon);
    }
}
