<?php

declare(strict_types=1);

namespace Stewart\Codegen\Console;

use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Generator;
use Stewart\Codegen\Model\Collection\GenerationWarningCollection;
use Stewart\Codegen\Output\CheckResult;
use Stewart\Codegen\Output\FileChange;
use Stewart\Codegen\Output\GenerationReport;
use Stewart\Codegen\Output\ShrinkPolicy;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Codegen\Snapshot\SnapshotFetcher;
use Stewart\Codegen\Snapshot\SnapshotFileReader;
use Stewart\Codegen\Snapshot\SnapshotFileWriter;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Config\CodegenConfig;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\Psr4Map;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Console\StewartCommand;
use Stewart\Runtime\Logging\LoggerFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Write typed entity and service classes from your Home Assistant')]
final class GenerateCommand extends StewartCommand
{
    public const string NAME = 'generate';

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly Psr4Map $psr4Map,
        private readonly ProjectRoot $projectRoot,
        private readonly LoggerFactory $loggers,
        private readonly SnapshotFileReader $snapshotReader,
        private readonly SnapshotFileWriter $snapshotWriter,
        private readonly SnapshotFetcher $snapshotFetcher,
        private readonly Generator $generator,
        private readonly GenerationOptionsFactory $generationOptionsFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('snapshot-in', null, InputOption::VALUE_REQUIRED, 'Generate from this file instead of connecting')
            ->addOption('snapshot-out', null, InputOption::VALUE_REQUIRED, 'Write what was read to this file')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Report what would change and fail if anything would')
            ->addOption('allow-shrink', null, InputOption::VALUE_NONE, 'Write even if that deletes more than half of the generated files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->config->loadConfig($this->findConfigOption($input));
            $mismatch = $this->describeAutoloadMismatch($config->codegen);

            if ($mismatch !== null) {
                $output->writeln($mismatch);

                return Command::FAILURE;
            }

            $snapshot = $this->loadSnapshot($input, $config);
            $this->copySnapshotIfRequested($input, $snapshot);

            return $this->generateFromSnapshot($input, $output, $config->codegen, $snapshot);
        } catch (StewartException $e) {
            return $this->printFailure($output, $e->getMessage());
        }
    }

    /** @throws StewartException */
    private function copySnapshotIfRequested(InputInterface $input, Snapshot $snapshot): void
    {
        $snapshotOut = $this->nonEmptyStringOption($input, 'snapshot-out');

        if ($snapshotOut !== null) {
            $this->snapshotWriter->writeSnapshot($snapshotOut, $snapshot);
        }
    }

    /** @throws StewartException */
    private function generateFromSnapshot(InputInterface $input, OutputInterface $output, CodegenConfig $codegen, Snapshot $snapshot): int
    {
        $target = new GenerationTarget($codegen->namespace->value, $codegen->outputDir->resolveWithin($this->projectRoot->path));
        $options = $this->generationOptionsFactory->createFromCodegenConfig($codegen);

        return $input->getOption('check') === true
            ? $this->printCheckResult($this->generator->checkAgainstSnapshot($snapshot, $target, $options), $output)
            : $this->printGenerationSummary($this->generator->writeFiles($snapshot, $target, $options, $this->selectShrinkPolicy($input)), $output);
    }

    private function selectShrinkPolicy(InputInterface $input): ShrinkPolicy
    {
        return $input->getOption('allow-shrink') === true ? ShrinkPolicy::Allow : ShrinkPolicy::Refuse;
    }

    private function loadSnapshot(InputInterface $input, StewartConfig $config): Snapshot
    {
        $snapshotIn = $this->nonEmptyStringOption($input, 'snapshot-in');
        $logger = $this->loggers->createStdoutLogger($config->logLevel, $config->logFormat);

        if ($snapshotIn !== null) {
            return $this->snapshotReader->readSnapshot($snapshotIn, $logger);
        }

        return $this->snapshotFetcher->fetchSnapshot($config->requireHomeAssistant(), $logger);
    }

    // Compared as strings: realpath fails while the directory does not exist yet.
    private function describeAutoloadMismatch(CodegenConfig $codegen): ?string
    {
        $expected = $this->normalizePath($codegen->outputDir->resolveWithin($this->projectRoot->path));

        foreach ($this->psr4Map->listDirectoriesFor($codegen->namespace->value . '\\') as $directory) {
            if ($this->normalizePath($directory) === $expected) {
                return null;
            }
        }

        return \sprintf(
            "<error>Composer does not map %s\\ to %s/.</error>\nAdd this to composer.json, then run `make install`:\n\n"
            . "    \"autoload\": {\n        \"psr-4\": {\n            \"%s\\\\\": \"%s/\"\n        }\n    }\n",
            $codegen->namespace->value,
            $codegen->outputDir->value,
            str_replace('\\', '\\\\', $codegen->namespace->value),
            $codegen->outputDir->value,
        );
    }

    private function printGenerationSummary(GenerationReport $report, OutputInterface $output): int
    {
        $this->printWarnings($output, $report->warnings);
        $output->writeln($report->summary());

        return Command::SUCCESS;
    }

    private function printCheckResult(CheckResult $result, OutputInterface $output): int
    {
        $this->printWarnings($output, $result->warnings);

        if ($result->isClean()) {
            $output->writeln('<info>The generated classes match Home Assistant.</info>');

            return Command::SUCCESS;
        }

        $rows = $result->listStaleFiles()->mapToList(
            static fn(FileChange $change): array => [$change->path, $change->outcome->value],
        );

        if ($rows !== []) {
            new Table($output)->setStyle('compact')->setHeaders(['file', 'would be'])->setRows($rows)->render();
        }

        $output->writeln('<comment>Run `make generate`.</comment>');

        return Command::FAILURE;
    }

    private function printWarnings(OutputInterface $output, GenerationWarningCollection $warnings): void
    {
        foreach ($warnings as $warning) {
            $output->writeln('<comment>' . OutputFormatter::escape($warning->describeWarning()) . '</comment>');
        }
    }

    private function printFailure(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . $message . '</error>');

        return Command::FAILURE;
    }

    // Composer's base directory contains `..`, and realpath fails before the first run.
    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return '/' . implode('/', $segments);
    }
}
