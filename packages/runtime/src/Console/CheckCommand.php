<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\App\UnloadableAppFile;
use Stewart\Runtime\Config\ConfigLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Check that the configuration loads and every automation file loads')]
final class CheckCommand extends StewartCommand
{
    public const string NAME = 'check';

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly AppDiscovery $discovery,
        private readonly AppCatalogResolver $catalogs,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $config = $this->config->loadConfig($this->config->locateConfigFile($this->findConfigOption($input)));
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

        $output->writeln(\sprintf('Configuration and %d automations load; %d enabled.', $discovered->apps->count(), $apps->enabled->count()));

        return Command::SUCCESS;
    }

    private function describeUnloadableFile(UnloadableAppFile $file): string
    {
        $cause = str_replace(["\r", "\n"], ' ', $file->cause->getMessage());

        return \sprintf('%s failed to load: %s', $file->path, $cause);
    }
}
