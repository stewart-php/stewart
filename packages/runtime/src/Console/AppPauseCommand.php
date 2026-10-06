<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Client\ControlTargetResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Pause one app: it stays loaded but skips deliveries and schedules until resumed')]
final class AppPauseCommand extends ControlCommand
{
    public const string NAME = 'app:pause';

    public function __construct(
        ConfigLoader $config,
        ControlTargetResolver $targets,
        private readonly ControlClient $client,
    ) {
        parent::__construct($config, $targets);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('app', InputArgument::REQUIRED, 'The automation ID, as shown by stewart status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->client->pauseApp($this->resolveControlTarget($input), new AppId($this->stringArgument($input, 'app')), $this->parseTimeoutOption($input));
        } catch (StewartException|StreamException|CancelledException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln($result->message);

        return Command::SUCCESS;
    }
}
