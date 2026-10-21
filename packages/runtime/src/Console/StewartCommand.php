<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Runtime\App\AppSelection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class StewartCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to stewart.yaml; defaults to stewart.yaml in the project root');
    }

    protected function findConfigOption(InputInterface $input): ?string
    {
        return $this->nonEmptyStringOption($input, 'config');
    }

    /** @throws IdentifierException */
    protected function readAppSelectionOption(InputInterface $input): AppSelection
    {
        return new AppSelection($this->findAppIdsOption($input, 'only'), $this->findAppIdsOption($input, 'trace'));
    }

    /** @throws IdentifierException */
    private function findAppIdsOption(InputInterface $input, string $name): ?AppIdCollection
    {
        $ids = $input->hasOption($name) ? $input->getOption($name) : null;

        if (!\is_array($ids) || $ids === []) {
            return null;
        }

        return AppIdCollection::fromList(array_map(static fn(string $id): AppId => new AppId($id), array_values(array_filter($ids, \is_string(...)))));
    }

    protected function nonEmptyStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    protected function writeMissingConfigFileNotice(OutputInterface $output, ?string $missingConfigFile): void
    {
        if ($missingConfigFile !== null) {
            $this->writeNotice($output, \sprintf('No config file at %s; using the environment only.', $missingConfigFile));
        }
    }

    protected function writeNotice(OutputInterface $output, string $notice): void
    {
        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('<comment>' . $notice . '</comment>');
    }

    protected function readStringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : '';
    }
}
