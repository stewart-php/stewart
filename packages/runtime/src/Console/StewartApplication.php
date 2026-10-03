<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class StewartApplication extends Application
{
    private const string END_OF_OPTIONS = '--';

    public function __construct(string $version)
    {
        parent::__construct('Stewart', $version);
        $this->setDefaultCommand(RunCommand::NAME);
    }

    protected function getCommandName(InputInterface $input): ?string
    {
        if (!$input instanceof ArgvInput) {
            return parent::getCommandName($input);
        }

        // Options before the command name belong to the default command, so their values are not command names.
        return $this->findCommandNameAfterLeadingOptions($input->getRawTokens(), $this->buildLeadingOptionDefinition());
    }

    /** @param list<string> $tokens */
    private function findCommandNameAfterLeadingOptions(array $tokens, InputDefinition $leadingOptions): ?string
    {
        $skipNext = false;

        foreach ($tokens as $index => $token) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if ($token === self::END_OF_OPTIONS) {
                return $tokens[$index + 1] ?? null;
            }

            if ($token === '' || $token === '-' || $token[0] !== '-') {
                return $token;
            }

            $skipNext = $this->findLeadingOption($token, $leadingOptions)?->acceptValue() ?? false;
        }

        return null;
    }

    private function findLeadingOption(string $token, InputDefinition $leadingOptions): ?InputOption
    {
        if (str_contains($token, '=')) {
            return null;
        }

        if (str_starts_with($token, self::END_OF_OPTIONS)) {
            $name = substr($token, \strlen(self::END_OF_OPTIONS));

            return $leadingOptions->hasOption($name) ? $leadingOptions->getOption($name) : null;
        }

        $shortcut = substr($token, 1);

        return \strlen($shortcut) === 1 && $leadingOptions->hasShortcut($shortcut) ? $leadingOptions->getOptionForShortcut($shortcut) : null;
    }

    private function buildLeadingOptionDefinition(): InputDefinition
    {
        return new InputDefinition([
            ...$this->getDefinition()->getOptions(),
            ...$this->get(RunCommand::NAME)->getNativeDefinition()->getOptions(),
        ]);
    }
}
