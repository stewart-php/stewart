<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Client\ControlTargetResolver;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class AppRequestCommand extends ControlCommand
{
    public function __construct(
        ConfigLoader $config,
        ControlTargetResolver $targets,
        protected readonly ControlClient $client,
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
            $result = $this->sendAppRequest($this->resolveControlTarget($input), new AppId($this->readStringArgument($input, 'app')), $this->parseTimeoutOption($input));
        } catch (StewartException|StreamException|CancelledException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln($result->message);

        return Command::SUCCESS;
    }

    /** @throws StewartException|Throwable */
    abstract protected function sendAppRequest(ControlTarget $target, AppId $appId, Duration $timeout): CommandResult;
}
