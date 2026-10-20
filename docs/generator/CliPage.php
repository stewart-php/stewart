<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final readonly class CliPage implements ReferencePage
{
    private const array SYMFONY_COMMANDS = ['help', 'list', 'completion', '_complete'];

    private const string MAKE_TARGET = '/^([a-zA-Z_-]+):.*?## (.+)$/m';

    public function __construct(
        private Application $console,
        private string $skeletonMakefile,
    ) {}

    public function getFileName(): string
    {
        return 'reference/cli.md';
    }

    public function renderMarkdown(): string
    {
        $sections = [
            'Your project runs these commands with `make`. The `stewart` commands below run inside the container:',
            '`make sh`, then `stewart …`, or `docker compose exec stewart stewart …` while Stewart runs in the background.',
            '',
            '## make',
            '',
            MarkdownTable::formatTable(['Command', 'What it does'], $this->listMakeTargets()),
            '',
            '## stewart',
        ];

        foreach ($this->listCommands() as $command) {
            $sections[] = $this->renderCommand($command);
        }

        return new MarkdownPage('Command reference', 'Every make target of a project and every stewart command, with its options.', 2, implode("\n", $sections))
            ->renderMarkdown();
    }

    /** @return list<list<string>> */
    private function listMakeTargets(): array
    {
        $makefile = file_get_contents($this->skeletonMakefile);
        preg_match_all(self::MAKE_TARGET, $makefile === false ? '' : $makefile, $matches, \PREG_SET_ORDER);

        return array_map(static fn(array $match): array => [MarkdownTable::formatCode('make ' . $match[1]), $match[2]], $matches);
    }

    /** @return list<Command> */
    private function listCommands(): array
    {
        $commands = array_filter(
            $this->console->all(),
            static fn(Command $command): bool => !$command->isHidden() && !\in_array($command->getName(), self::SYMFONY_COMMANDS, true),
        );
        ksort($commands);

        return array_values($commands);
    }

    private function renderCommand(Command $command): string
    {
        // Running a command merges the console's global options into its definition.
        $definition = $command->getNativeDefinition();
        $lines = ['', '### ' . $command->getName(), '', $command->getDescription(), '', '```', 'stewart ' . $command->getSynopsis(true), '```'];
        $arguments = array_map(
            static fn(InputArgument $argument): array => [MarkdownTable::formatCode($argument->getName()), $argument->getDescription()],
            array_values($definition->getArguments()),
        );
        $options = array_map(
            fn(InputOption $option): array => [$this->formatOptionName($option), $option->getDescription(), $this->formatDefault($option)],
            array_values($definition->getOptions()),
        );

        if ($arguments !== []) {
            array_push($lines, '', MarkdownTable::formatTable(['Argument', 'Meaning'], $arguments));
        }

        if ($options !== []) {
            array_push($lines, '', MarkdownTable::formatTable(['Option', 'Meaning', 'Default'], $options));
        }

        return implode("\n", $lines);
    }

    private function formatOptionName(InputOption $option): string
    {
        $shortcut = $option->getShortcut();
        $name = '--' . $option->getName() . ($option->acceptValue() ? '=…' : '');

        return MarkdownTable::formatCode($shortcut === null ? $name : '-' . $shortcut . ', ' . $name);
    }

    private function formatDefault(InputOption $option): string
    {
        $default = $option->getDefault();

        return \is_string($default) || \is_int($default) || \is_float($default) ? MarkdownTable::formatCode((string) $default) : '';
    }
}
