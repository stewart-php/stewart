<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Runtime\Broker\GeneratedManifest;
use Stewart\Runtime\Broker\Reload\ReloadCheckReport;
use Stewart\Runtime\Broker\Reload\ReloadCheckReportCodec;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Console\Check\ProjectCheck;
use Stewart\Runtime\Console\Check\ProjectCheckResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// Run by the daemon in a child process: unlike the daemon, it loads the app classes as they are now.
#[AsCommand(name: self::NAME, description: 'Check the project for a reload and print what it found as JSON', hidden: true)]
final class ReloadCheckCommand extends StewartCommand
{
    public const string NAME = 'reload:check';

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly ProjectCheck $projects,
        private readonly ReloadCheckReportCodec $reports,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check only this automation, even if disabled; repeat for more');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $this->findConfigOption($input);
        $fingerprint = $this->config->fingerprintConfigFile($configFile);

        try {
            $result = $this->projects->checkProject($configFile, $this->readAppSelectionOption($input));
        } catch (IdentifierException $e) {
            return $this->writeReport($output, ReloadCheckReport::failed([$e->getMessage()], $fingerprint));
        }

        if (!$result->hasPassed()) {
            return $this->writeReport($output, ReloadCheckReport::failed($result->errors, $fingerprint));
        }

        // A reload keeps the running listeners, but must not accept a config the next start refuses.
        if ($result->httpListenerError !== null) {
            return $this->writeReport($output, ReloadCheckReport::failed([$result->httpListenerError], $fingerprint));
        }

        return $this->writeReport($output, ReloadCheckReport::fromDiscovery($result->discovered, $fingerprint, $this->findGeneratedManifest($result)));
    }

    private function findGeneratedManifest(ProjectCheckResult $result): ?GeneratedManifest
    {
        return $result->codegen === null ? null : GeneratedManifest::findForGeneratedCode($result->codegen);
    }

    private function writeReport(OutputInterface $output, ReloadCheckReport $report): int
    {
        $output->writeln($this->reports->encodeReport($report), OutputInterface::OUTPUT_RAW);

        return $report->findFirstError() === null ? Command::SUCCESS : Command::FAILURE;
    }
}
