<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Client\ControlTargetResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Check the project, then restart every worker on the current code, keeping the Home Assistant session')]
final class ReloadCommand extends ControlCommand
{
    public const string NAME = 'reload';

    public function __construct(
        ConfigLoader $config,
        ControlTargetResolver $targets,
        private readonly ControlClient $client,
    ) {
        parent::__construct($config, $targets);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->client->requestReload($this->resolveControlTarget($input), $this->parseTimeoutOption($input));
        } catch (StewartException|StreamException|CancelledException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->writeCommandResult($output, $result);

        return Command::SUCCESS;
    }
}
