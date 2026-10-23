<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\RemoteToggle;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Testing\App\AppHarness;

final class RemoteToggleTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->startApp(RemoteToggle::class, ['remote' => 'aa:bb', 'light' => 'light.desk']);
    }

    public function testToggleOnTheRemoteTogglesTheLight(): void
    {
        $this->harness->ha->pushEvent(new HaEvent('zha_event', ['device_ieee' => 'aa:bb', 'command' => 'toggle']));

        $call = $this->harness->ha->getLastCall();
        self::assertSame('light.toggle', $call->getActionName());
        self::assertSame(['light.desk'], $call->listTargetedEntityIds());
    }

    public function testOtherRemotesAreIgnored(): void
    {
        $this->harness->ha->pushEvent(new HaEvent('zha_event', ['device_ieee' => 'cc:dd', 'command' => 'toggle']));

        self::assertTrue($this->harness->ha->calls->isEmpty());
    }
}
