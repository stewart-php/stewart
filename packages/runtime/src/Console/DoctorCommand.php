<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Runtime\Console\Doctor\ConnectionChecks;
use Stewart\Runtime\Console\Doctor\ContainerChecks;
use Stewart\Runtime\Console\Doctor\DoctorOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Check that this PHP can run Stewart, and that it reaches Home Assistant and the store')]
final class DoctorCommand extends StewartCommand
{
    public const string NAME = 'doctor';

    private const string OFFLINE_OPTION = 'offline';

    public function __construct(
        private readonly ContainerChecks $containerChecks,
        private readonly ConnectionChecks $connectionChecks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(self::OFFLINE_OPTION, null, InputOption::VALUE_NONE, 'Check only this container, not the settings and connections');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Composer's platform check already refused an older PHP before this command could load.
        $output->writeln(\sprintf('PHP %s (%s)', \PHP_VERSION, \PHP_BINARY));
        $findings = $this->containerChecks->runContainerChecks();

        if ($input->getOption(self::OFFLINE_OPTION) !== true) {
            array_push($findings, ...$this->connectionChecks->runConnectionChecks($this->findConfigOption($input)));
        }

        $healthy = true;

        foreach ($findings as $finding) {
            $output->writeln(rtrim(\sprintf('  %-12s %s  %s', $finding->label, $finding->outcome->getMark(), $finding->detail)));
            $healthy = $healthy && $finding->outcome !== DoctorOutcome::Fail;
        }

        $output->writeln($healthy ? 'All good.' : 'Problems found.');

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
