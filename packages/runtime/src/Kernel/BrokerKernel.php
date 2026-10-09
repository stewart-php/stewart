<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use Psr\Log\LoggerInterface;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\BrokerLifecycle;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Config\CodegenConfig;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Config\GitDeployConfig;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Config\SupervisionConfig;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class BrokerKernel
{
    public function __construct(
        private string $daemonVersion,
        private string $userServicesFile,
        private ProjectRoot $projectRoot,
        private SyntheticServices $overrides = new SyntheticServices(),
        private ProfileCompiler $compiler = new ProfileCompiler(),
    ) {}

    /** @throws ConfigurationException */
    public function createBroker(StewartConfig $config, AppCatalog $apps, LoggerInterface $logger, ?string $configFileOption = null): BrokerLifecycle
    {
        $this->warnAboutSlowInitializeCalls($config, $logger);
        $this->warnAboutReadyTimeoutBelowInitialize($config, $logger);

        return $this->compiler
            ->compileProfileContainer(ContainerProfile::Broker, $this->buildSyntheticServicesFor($config, $apps, $logger)->withOverrides($this->overrides), $this->buildNamedArgumentsFor($config, $configFileOption))
            ->resolveService(BrokerLifecycle::class);
    }

    /** @throws ConfigurationException */
    private function buildSyntheticServicesFor(StewartConfig $config, AppCatalog $apps, LoggerInterface $logger): SyntheticServices
    {
        return new SyntheticServices()
            ->withService(StewartConfig::class, $config)
            ->withService(AppCatalog::class, $apps)
            ->withService(AppDefinitionCollection::class, $apps->enabled)
            ->withService(LoggerInterface::class, $logger)
            ->withService(ConnectionConfig::class, $config->requireHomeAssistant())
            ->withService(SupervisionConfig::class, $config->supervision)
            ->withService(ServiceCallPolicy::class, $config->serviceCalls)
            ->withService(ControlConfig::class, $config->control)
            ->withService(HttpConfig::class, $config->http)
            ->withService(ProjectRoot::class, $this->projectRoot)
            ->withService(CodegenConfig::class, $config->codegen)
            ->withService(GitDeployConfig::class, $config->gitDeploy)
            ->withService(ExposeConfig::class, $config->expose)
            ->withService(OutboxLimits::class, new OutboxLimits($config->workerEventBuffer, $config->workerStateBatch));
    }

    private function buildNamedArgumentsFor(StewartConfig $config, ?string $configFileOption): NamedArguments
    {
        return new NamedArguments()
            ->withArgument('daemonVersion', $this->daemonVersion)
            ->withArgument('componentSessionStewartVersion', $this->daemonVersion)
            ->withArgument('reconnectBackoff', $config->reconnectBackoff)
            ->withArgument('userServicesFile', $this->userServicesFile)
            ->withArgument('brokerShutdownGrace', $config->shutdownGrace)
            ->withArgument('poolWorkerCount', $config->workers)
            ->withArgument('persistenceConfig', $config->persistence)
            ->withArgument('storeConfigured', $config->persistence !== null)
            ->withArgument('appPauseOverridePrefix', $config->persistence?->prefix)
            ->withArgument('workerRestartAttempts', $config->supervision->restartAttempts)
            ->withArgument('workerRestartWindow', $config->supervision->restartWindow)
            ->withArgument('releaseCheckConfigFile', $configFileOption);
    }

    /** @throws ConfigurationException */
    private function warnAboutSlowInitializeCalls(StewartConfig $config, LoggerInterface $logger): void
    {
        $initializeTimeout = $config->supervision->initializeTimeout->findDuration();
        $commandTimeout = $config->requireHomeAssistant()->commandTimeout;

        if ($initializeTimeout !== null && $commandTimeout->isLongerThan($initializeTimeout)) {
            $logger->warning('An app initializing with a single slow service call will be dropped before that call times out', [
                'initialize_timeout' => (string) $initializeTimeout,
                'command_timeout' => (string) $commandTimeout,
            ]);
        }
    }

    private function warnAboutReadyTimeoutBelowInitialize(StewartConfig $config, LoggerInterface $logger): void
    {
        $readyTimeout = $config->supervision->readyTimeout->findDuration();
        $initializeTimeout = $config->supervision->initializeTimeout->findDuration();

        if ($readyTimeout === null) {
            return;
        }

        if ($initializeTimeout === null) {
            $logger->warning('A hanging initialize() restarts its whole worker, because initialize_timeout is off while ready_timeout is on', [
                'ready_timeout' => (string) $readyTimeout,
            ]);

            return;
        }

        if (!$readyTimeout->isLongerThan($initializeTimeout)) {
            $logger->warning('A worker is restarted before an app that uses its whole initialize_timeout can report ready', [
                'initialize_timeout' => (string) $initializeTimeout,
                'ready_timeout' => (string) $readyTimeout,
            ]);
        }
    }
}
