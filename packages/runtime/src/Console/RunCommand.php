<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\App\CatalogWarnings;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Kernel\BrokerKernel;
use Stewart\Runtime\Lifecycle\BrokerStopOutcome;
use Stewart\Runtime\Logging\LogFormat;
use Stewart\Runtime\Logging\LoggerFactory;
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
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Reload after every saved change to the apps, services.php or stewart.yaml');
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
        );
        $config = $this->config->loadConfig($launch->configFile, $launch->listConfigOverrides());
        // Validated before the logger exists, so a missing home_assistant section fails as a config error.
        $config->requireHomeAssistant();
        $discovered = $this->discovery->discover();
        $selection = $launch->selection;
        $apps = $this->catalogs->resolveCatalog($discovered, $config->apps, $config->workers, $selection);
        $logger = $this->loggers->createStdoutLogger($config->logLevel, $this->logFormatFromOptions($input, $config->logFormat));

        $this->noteMissingConfigFile($this->config->findMissingDefaultFile($launch->configFile), $logger);
        $this->catalogWarnings->logCatalogWarnings($discovered, $apps, $this->config->locateConfigFile($launch->configFile), $logger);

        if ($config->serviceCalls->dryRun) {
            $logger->warning('service_calls.dry_run is on: service calls are logged, not sent to Home Assistant.');
        }

        if ($selection->onlyIds !== null) {
            $logger->notice('Running only the automations named by --only.', ['apps' => $selection->onlyIds->toStrings()]);
        }

        return new PreparedDaemon($this->brokers->createBroker($config, $apps, $logger, $launch), $logger);
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
