<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\App\DiscoveredApp;
use Stewart\Runtime\App\UnloadableAppFile;
use Stewart\Runtime\Broker\AppPlacement;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Container\AppContainerBlueprint;
use Stewart\Runtime\Container\AppContainerBuilder;
use Stewart\Runtime\Container\Collection\AppBuildFailureCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Check that the configuration loads and every app can be built')]
final class CheckCommand extends StewartCommand
{
    public const string NAME = 'check';

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly AppDiscovery $discovery,
        private readonly AppCatalogResolver $catalogs,
        private readonly AppPlacement $placement,
        private readonly AppContainerBuilder $containers,
        private readonly string $userServicesFile,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $config = $this->config->loadConfig($this->findConfigOption($input));
            $config->requireHomeAssistant();
            $discovered = $this->discovery->discover();
            $apps = $this->catalogs->resolveCatalog($discovered, $config->apps, $config->workers);
        } catch (StewartException $e) {
            $errors->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (!$discovered->unloadable->isEmpty()) {
            foreach ($discovered->unloadable as $file) {
                $errors->writeln('<error>' . $this->describeUnloadableFile($file) . '</error>');
            }

            return Command::FAILURE;
        }

        $slots = $this->placement->planWorkerSlots($apps->enabled, $config);

        try {
            $failures = $this->findAppBuildFailures($slots, AppContainerBlueprint::fromProjectConfig($config, $apps->knownIds, $this->userServicesFile));
        } catch (StewartException $e) {
            $errors->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (!$failures->isEmpty()) {
            foreach ($apps->enabled as $app) {
                $failure = $failures->find($app->id);

                if ($failure !== null) {
                    $errors->writeln('<error>' . $failure->error->getMessage() . '</error>');
                }
            }

            return Command::FAILURE;
        }

        $this->writeMissingConfigFileNotice($output, $this->config->findMissingDefaultFile($this->findConfigOption($input)));
        $this->writeUnmatchedOverridesNotice($output, $apps);
        $this->writeUnmarkedAppNotices($output, $discovered->unmarked);
        $this->writeAppTable($output, $discovered->apps, $apps, $slots);
        $output->writeln(\sprintf('Configuration and %d automations load; %d enabled.', $discovered->apps->count(), $apps->enabled->count()));

        return Command::SUCCESS;
    }

    /** @throws StewartException */
    private function findAppBuildFailures(WorkerSlotCollection $slots, AppContainerBlueprint $blueprint): AppBuildFailureCollection
    {
        $failures = [];

        foreach ($slots as $slot) {
            foreach ($this->containers->findAppBuildFailures($slot->listWorkerApps(), $blueprint, $slot->workerId) as $failure) {
                $failures[] = $failure;
            }
        }

        return AppBuildFailureCollection::keyedByAppId($failures);
    }

    private function writeUnmarkedAppNotices(OutputInterface $output, UnmarkedAppCollection $unmarked): void
    {
        foreach ($unmarked as $app) {
            $this->writeNotice($output, \sprintf('%s implements App but is not marked #[Automation], so it will not run.', $app->class));
        }
    }

    private function writeUnmatchedOverridesNotice(OutputInterface $output, AppCatalog $apps): void
    {
        if (!$apps->unmatchedOverrideIds->isEmpty()) {
            $this->writeNotice($output, \sprintf(
                'stewart.yaml or the environment configures automations that were not found, so their settings are ignored: %s. Known automations: %s.',
                implode(', ', $apps->unmatchedOverrideIds->toStrings()),
                $apps->knownIds->isEmpty() ? 'none' : implode(', ', $apps->knownIds->toStrings()),
            ));
        }
    }

    private function writeAppTable(OutputInterface $output, DiscoveredAppCollection $discovered, AppCatalog $apps, WorkerSlotCollection $slots): void
    {
        $rows = $discovered->mapToList(static function (DiscoveredApp $app) use ($apps, $slots): array {
            $definition = $apps->enabled->find($app->id);
            $state = $definition === null ? 'disabled' : ($definition->startsPaused ? 'paused' : 'enabled');

            return [$app->id->value, $app->class, (string) ($slots->findWorkerOfApp($app->id) ?? '-'), $state];
        });

        if ($rows !== []) {
            new Table($output)->setStyle('compact')->setHeaders(['app', 'class', 'worker', 'state'])->setRows($rows)->render();
        }
    }

    private function describeUnloadableFile(UnloadableAppFile $file): string
    {
        $cause = str_replace(["\r", "\n"], ' ', $file->cause->getMessage());

        return \sprintf('%s failed to load: %s', $file->path, $cause);
    }
}
