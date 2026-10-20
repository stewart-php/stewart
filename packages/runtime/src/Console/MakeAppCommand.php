<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Console\Scaffold\AppScaffold;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Create an app in apps/ and its test in tests/')]
final class MakeAppCommand extends Command
{
    public const string NAME = 'make:app';

    public function __construct(private readonly AppScaffold $scaffold)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The class name, such as PorchLight');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'The automation ID; derived from the name when left out, such as porch-light');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $id = $input->getOption('id');

        try {
            $app = $this->scaffold->scaffoldApp(\is_string($name) ? $name : '', \is_string($id) ? $id : null);
        } catch (StewartException $e) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(\sprintf('Created %s and %s.', $app->appFile, $app->testFile));
        $output->writeln(\sprintf('Its ID is %1$s; its options go under apps.%1$s in stewart.yaml.', $app->id->value));

        return Command::SUCCESS;
    }
}
