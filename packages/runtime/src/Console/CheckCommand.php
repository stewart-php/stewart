<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\App\DiscoveredApp;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Console\Check\ProjectCheck;
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
        private readonly ProjectCheck $projects,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $this->findConfigOption($input);
        $result = $this->projects->checkProject($configFile);

        if (!$result->hasPassed()) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

            foreach ($result->errors as $error) {
                $errors->writeln('<error>' . $error . '</error>');
            }

            return Command::FAILURE;
        }

        $discovered = $result->discovered;
        $apps = $result->catalog;

        $this->writeMissingConfigFileNotice($output, $this->config->findMissingDefaultFile($configFile));
        $this->writeHttpListenerNotice($output, $result->httpListenerError);
        $this->writeUnmatchedOverridesNotice($output, $apps);
        $this->writeUnmarkedAppNotices($output, $discovered->unmarked);
        $this->writeUnusedTemplateNotices($output, $discovered->listUnusedTemplateClasses());
        $this->writeDisposeDeprecationNotices($output, $discovered->apps);
        $this->writeAppTable($output, $discovered->apps, $apps, $result->workerSlots);
        $output->writeln(\sprintf('Configuration and %d automations load; %d enabled.', $discovered->apps->count(), $apps->enabled->count()));

        return Command::SUCCESS;
    }

    private function writeHttpListenerNotice(OutputInterface $output, ?string $error): void
    {
        if ($error !== null) {
            $this->writeNotice($output, $error . ' The daemon refuses to start until it is fixed.');
        }
    }

    private function writeUnmarkedAppNotices(OutputInterface $output, UnmarkedAppCollection $unmarked): void
    {
        foreach ($unmarked as $app) {
            $this->writeNotice($output, \sprintf('%s implements App but is not marked #[Automation] or #[AppTemplate], so it will not run.', $app->class));
        }
    }

    /** @param list<class-string> $templates */
    private function writeUnusedTemplateNotices(OutputInterface $output, array $templates): void
    {
        foreach ($templates as $template) {
            $this->writeNotice($output, \sprintf('%s is an #[AppTemplate], but no apps.<id>.class names it, so it does not run.', $template));
        }
    }

    private function writeDisposeDeprecationNotices(OutputInterface $output, DiscoveredAppCollection $apps): void
    {
        $noticed = [];

        foreach ($apps as $app) {
            if (!isset($noticed[$app->class]) && $app->declaresDisposeWithoutDisposable()) {
                $noticed[$app->class] = true;
                $this->writeNotice($output, \sprintf(
                    '%s declares dispose() without implementing Disposable; delete it if it is empty, or add "implements Disposable". Stewart 2.0 stops calling it.',
                    $app->class,
                ));
            }
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
}
