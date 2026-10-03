<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Runtime\Config\EnvironmentOverlay;
use Stewart\Runtime\Config\StewartConfigSchema;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Show every supported option with its type and default')]
final class ConfigReferenceCommand extends Command
{
    public const string NAME = 'config:reference';

    public function __construct(private readonly StewartConfigSchema $schema)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([
            '# Every setting stewart.yaml understands.',
            '#',
            '# Any of them can be set from the environment instead: ' . EnvironmentOverlay::PREFIX
                . ', then the keys,',
            '# uppercased, joined by a double underscore. A single underscore belongs to a key',
            '# name. App options and lists take inline YAML, such as [hall, kitchen].',
            '#',
            '#   log_level               ->  ' . EnvironmentOverlay::PREFIX . 'LOG_LEVEL',
            '#   apps.demo.worker        ->  ' . EnvironmentOverlay::PREFIX . 'APPS__DEMO__WORKER',
            '#   apps.demo.options.rooms ->  ' . EnvironmentOverlay::PREFIX . 'APPS__DEMO__OPTIONS__ROOMS',
            '#',
            '# A value in this file can also read a variable: ${HA_TOKEN}, or ${HA_TOKEN:-fallback}.',
            '# The environment wins over this file, and --log-level wins over both.',
            '',
        ]);

        $output->writeln($this->reference());

        return Command::SUCCESS;
    }

    private function reference(): string
    {
        $dumped = new YamlReferenceDumper()->dump($this->schema);
        $lines = explode("\n", $dumped);

        if (str_starts_with($dumped, StewartConfigSchema::ROOT . ":\n")) {
            array_shift($lines);
        }

        return implode("\n", array_map(
            static fn(string $line): string => preg_replace('/^ {4}/', '', $line) ?? $line,
            $lines,
        ));
    }
}
