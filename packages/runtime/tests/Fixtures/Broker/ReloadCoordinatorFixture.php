<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Runtime\Broker\BrokerRun;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\Deploy\ExternalCommandRunner;
use Stewart\Runtime\Broker\Mqtt\DisabledMqttLink;
use Stewart\Runtime\Broker\Reload\ReloadCheck;
use Stewart\Runtime\Broker\Reload\ReloadCoordinator;
use Stewart\Runtime\Broker\Reload\ReloadState;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Runtime\Tests\Fixtures\Deploy\ScriptedCommandRunner;

final class ReloadCoordinatorFixture
{
    public const string CONSOLE_SCRIPT = '/project/vendor/bin/stewart';

    private function __construct() {}

    public static function createReloadCoordinator(
        WorkerPoolFixture $pools,
        ?BrokerRun $run = null,
        ExternalCommandRunner $commands = new ScriptedCommandRunner(),
        ReloadState $state = new ReloadState(),
        LoggerInterface $logger = new NullLogger(),
        DaemonLaunchOptions $launch = new DaemonLaunchOptions(),
    ): ReloadCoordinator {
        return new ReloadCoordinator(
            new ReloadCheck($commands, $launch, self::CONSOLE_SCRIPT),
            $state,
            $pools->pool,
            $run ?? self::createRunningBroker($pools),
            $pools->clock,
            $logger,
        );
    }

    private static function createRunningBroker(WorkerPoolFixture $pools): BrokerRun
    {
        $logger = new NullLogger();
        $run = new BrokerRun($pools->pool, $pools->watchdog, FakeHaSession::createOpened(), new DisabledMqttLink($logger), $logger, ConfigFixture::createStewartConfig(), new DaemonStartTime($pools->clock));
        $run->start();

        return $run;
    }
}
