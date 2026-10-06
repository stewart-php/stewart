<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

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

    protected function nonEmptyStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    protected function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : '';
    }
}
