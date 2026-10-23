<?php

declare(strict_types=1);

namespace App\Tests;

use App\HelloApp;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\App\AppHarness;

final class HelloAppTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('sun.sun', 'above_horizon');
        $this->harness->ha->seedState('light.porch', 'off');
        $this->harness->startApp(HelloApp::class, ['watch' => 'sun.sun']);
    }

    public function testStartingShowsANotification(): void
    {
        $call = $this->harness->ha->getLastCall();

        self::assertSame('persistent_notification.create', $call->getActionName());
        self::assertSame('stewart_hello', $call->data['notification_id'] ?? null);
        self::assertSame('Stewart is connected and can see 2 entities.', $call->data['message'] ?? null);
    }

    public function testChangesOfTheWatchedEntityAreLogged(): void
    {
        $this->harness->ha->pushState('sun.sun', 'below_horizon');

        self::assertContains('State changed', $this->harness->logger->listMessagesAt('info'));
    }
}
