<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use DateTimeZone;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Runtime\Worker\WorkerSession;

final readonly class WorkerKernel
{
    private const string PROCESS_ID_PREFIX = 'w';

    public function __construct(private SyntheticServices $overrides = new SyntheticServices()) {}

    public function run(Transport $transport): string
    {
        try {
            $bootstrap = $transport->receive();
        } catch (TransportException $e) {
            return 'worker stopped before bootstrap: ' . $e->getMessage();
        }

        if (!$bootstrap instanceof Bootstrap) {
            return \sprintf('worker stopped: expected Bootstrap first, got %s', get_debug_type($bootstrap));
        }

        $clock = new SystemClock(new DateTimeZone($bootstrap->timeZone));
        $container = new ProfileCompiler()->compileProfileContainer(
            ContainerProfile::Worker,
            $this->buildSyntheticServicesFor($transport, $bootstrap, $clock)->withOverrides($this->overrides),
            $this->buildNamedArgumentsFor($bootstrap),
        );

        $container->resolveService(ProcessTimeZone::class)->useAsProcessDefault($clock->getTimeZone());

        return $container->resolveService(WorkerSession::class)->run();
    }

    private function buildSyntheticServicesFor(Transport $transport, Bootstrap $bootstrap, SystemClock $clock): SyntheticServices
    {
        return new SyntheticServices()
            ->withService(Transport::class, $transport)
            ->withService(Bootstrap::class, $bootstrap)
            ->withService(WorkerId::class, $bootstrap->workerId)
            ->withService(Clock::class, $clock);
    }

    private function buildNamedArgumentsFor(Bootstrap $bootstrap): NamedArguments
    {
        return new NamedArguments()
            ->withArgument('subscriptionIdPrefix', self::PROCESS_ID_PREFIX . $bootstrap->workerId->value)
            ->withArgument('scheduleIdPrefix', self::PROCESS_ID_PREFIX . $bootstrap->workerId->value)
            ->withArgument('workerLogThreshold', $bootstrap->settings->logLevel)
            ->withArgument('subscriptionQueueLimit', $bootstrap->settings->subscriptionBuffer)
            ->withArgument('workerCallTimeout', $bootstrap->settings->callTimeout)
            ->withArgument('historyQueryTimeout', $bootstrap->settings->callTimeout)
            ->withArgument('workerShutdownGrace', $bootstrap->settings->shutdownGrace)
            ->withArgument('generatedNamespace', $bootstrap->settings->generatedNamespace)
            ->withArgument('workerStoreSettings', $bootstrap->store)
            ->withArgument('brokerMqttEnabled', $bootstrap->settings->mqttEnabled);
    }
}
