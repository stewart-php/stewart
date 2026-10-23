<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\App\AppInstanceResolver;
use Stewart\Runtime\App\CatalogWarnings;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Kernel\BrokerKernel;
use Stewart\Runtime\Lifecycle\BrokerStopOutcome;
use Stewart\Runtime\Logging\LogFormat;
use Stewart\Runtime\Logging\LoggerFactory;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: self::NAME, description: 'Run the Stewart daemon')]
final class RunCommand extends StewartCommand
{
    public const string NAME = 'run';

    // sysexits EX_TEMPFAIL: the container entrypoint starts the daemon again on the staged release.
    public const int RESTART_EXIT_CODE = 75;

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly AppDiscovery $discovery,
        private readonly AppInstanceResolver $instances,
        private readonly AppCatalogResolver $catalogs,
        private readonly BrokerKernel $brokers,
        private readonly LoggerFactory $loggers,
        private readonly CatalogWarnings $catalogWarnings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('log-level', 'l', InputOption::VALUE_REQUIRED, 'debug|info|notice|warning|error')
            ->addOption('json-logs', null, InputOption::VALUE_NONE, 'Emit logs as JSON')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only this automation, even if disabled; repeat for more')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Log TRACE lines for this automation at any log level; repeat for more')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Reload after every saved change to the apps, services.php or stewart.yaml')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Log actions, event fires and registry edits instead of sending them (ha_calls.dry_run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $daemon = $this->prepareDaemon($input);
        } catch (StewartException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        try {
            $outcome = $daemon->broker->run();
        } catch (Throwable $e) {
            $daemon->logger->error('Stewart stopped', ['exception' => $e]);

            return Command::FAILURE;
        }

        return match ($outcome) {
            BrokerStopOutcome::Stopped => Command::SUCCESS,
            BrokerStopOutcome::RestartRequested => self::RESTART_EXIT_CODE,
        };
    }

    /** @throws StewartException */
    private function prepareDaemon(InputInterface $input): PreparedDaemon
    {
        $launch = new DaemonLaunchOptions(
            $this->findConfigOption($input),
            $this->readAppSelectionOption($input),
            $this->nonEmptyStringOption($input, 'log-level'),
            $input->getOption('watch') === true,
            $input->getOption('dry-run') === true,
        );
        $config = $this->config->loadConfig($launch->configFile, $launch->listConfigOverrides());
        // Validated before the logger exists, so a missing home_assistant section fails as a config error.
        $config->requireHomeAssistant();
        $discovered = $this->instances->resolveInstances($this->discovery->discover(), $config->apps);
        $selection = $launch->selection;
        $apps = $this->catalogs->resolveCatalog($discovered, $config->apps, $config->workers, $selection);
        $verbosity = new LogVerbosityPolicy($config->logLevel, $apps->enabled->listVerbositiesByAppId());
        $logger = $this->loggers->createDaemonLogger($verbosity, $this->logFormatFromOptions($input, $config->logFormat));

        $this->noteMissingConfigFile($this->config->findMissingDefaultFile($launch->configFile), $logger);
        $this->catalogWarnings->logCatalogWarnings($discovered, $apps, $this->config->locateConfigFile($launch->configFile), $logger);

        if ($config->haCalls->dryRun) {
            $logger->warning('ha_calls.dry_run is on: actions, event fires and registry edits are logged, not sent to Home Assistant.');
        }

        if ($selection->onlyIds !== null) {
            $logger->notice('Running only the automations named by --only.', ['apps' => $selection->onlyIds->toStrings()]);
        }

        if ($selection->tracedIds !== null) {
            $logger->notice('Tracing the automations named by --trace.', ['apps' => $selection->tracedIds->toStrings()]);
        }

        return new PreparedDaemon($this->brokers->createBroker($config, $apps, $logger, $verbosity, $launch), $logger);
    }

    private function noteMissingConfigFile(?string $missingConfigFile, LoggerInterface $logger): void
    {
        if ($missingConfigFile !== null) {
            $logger->info('No config file found; using the environment only.', ['config' => $missingConfigFile]);
        }
    }

    private function logFormatFromOptions(InputInterface $input, LogFormat $configured): LogFormat
    {
        return $input->getOption('json-logs') === true ? LogFormat::Json : $configured;
    }
}
