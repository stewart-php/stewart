<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\App\AppSelection;
use Stewart\Runtime\App\DiscoveryResult;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('log-level', 'l', InputOption::VALUE_REQUIRED, 'debug|info|notice|warning|error')
            ->addOption('json-logs', null, InputOption::VALUE_NONE, 'Emit logs as JSON')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only this automation, even if disabled; repeat for more');
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
        $configPath = $this->config->locateConfigFile($this->findConfigOption($input));
        $config = $this->config->loadConfig($configPath, $this->configOverridesFromOptions($input));
        // Validated before the logger exists, so a missing home_assistant section fails as a config error.
        $config->requireHomeAssistant();
        $discovered = $this->discovery->discover();
        $selection = $this->selectAppsFromOptions($input);
        $apps = $this->catalogs->resolveCatalog($discovered, $config->apps, $config->workers, $selection);
        $logger = $this->loggers->createStdoutLogger($config->logLevel, $this->logFormatFromOptions($input, $config->logFormat));

        $this->warnAboutDiscovery($discovered, $apps, $configPath, $logger);

        if ($config->serviceCalls->dryRun) {
            $logger->warning('service_calls.dry_run is on: service calls are logged, not sent to Home Assistant.');
        }

        if ($selection->onlyIds !== null) {
            $logger->notice('Running only the automations named by --only.', ['apps' => $selection->onlyIds->toStrings()]);
        }

        return new PreparedDaemon($this->brokers->createBroker($config, $apps, $logger, $this->findConfigOption($input)), $logger);
    }

    private function warnAboutDiscovery(DiscoveryResult $discovered, AppCatalog $apps, string $configPath, LoggerInterface $logger): void
    {
        foreach ($discovered->unmarked as $unmarked) {
            $logger->warning('A class in apps/ implements App but is not marked #[Automation], so it will not run.', [
                'class' => $unmarked->class,
            ]);
        }

        foreach ($discovered->unloadable as $file) {
            $logger->error('A file in apps/ failed to load, so the automation it declares will not run.', [
                'file' => $file->path,
                'exception' => $file->cause,
            ]);
        }

        if (!$apps->unmatchedOverrideIds->isEmpty()) {
            $logger->warning('stewart.yaml configures automations that were not found, possibly because their file failed to load.', [
                'apps' => $apps->unmatchedOverrideIds->toStrings(),
                'files' => $discovered->unloadable->listPaths(),
            ]);
        }

        if ($apps->enabled->isEmpty()) {
            $logger->warning('No automations to run; Stewart will connect and do nothing.', [
                'config' => $configPath,
                'found' => $discovered->apps->count(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function configOverridesFromOptions(InputInterface $input): array
    {
        $level = $this->nonEmptyStringOption($input, 'log-level');

        return $level === null ? [] : ['log_level' => $level];
    }

    /** @throws IdentifierException */
    private function selectAppsFromOptions(InputInterface $input): AppSelection
    {
        $only = $input->getOption('only');

        if (!\is_array($only) || $only === []) {
            return new AppSelection();
        }

        return new AppSelection(AppIdCollection::fromIds(array_map(static fn(string $id): AppId => new AppId($id), array_values(array_filter($only, \is_string(...))))));
    }

    private function logFormatFromOptions(InputInterface $input, LogFormat $configured): LogFormat
    {
        return $input->getOption('json-logs') === true ? LogFormat::Json : $configured;
    }
}
