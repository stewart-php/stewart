<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Client\ControlTargetResolver;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ControlCommand extends StewartCommand
{
    private const string DEFAULT_TIMEOUT = '5s';

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly ControlTargetResolver $targets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('address', null, InputOption::VALUE_REQUIRED, 'Where the daemon listens, such as unix://var/run/stewart.sock or tcp://host:port; defaults to control.listen')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'The control token; defaults to control.token, usually STEWART_CONTROL__TOKEN')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'How long to wait for the daemon, like 5s', self::DEFAULT_TIMEOUT);
    }

    /** @throws StewartException */
    protected function resolveControlTarget(InputInterface $input): ControlTarget
    {
        $path = $this->findConfigOption($input);

        return $this->targets->resolveTarget(
            $this->nonEmptyStringOption($input, 'address'),
            $this->nonEmptyStringOption($input, 'token'),
            fn(): ControlConfig => $this->config->loadConfig($path)->control,
        );
    }

    protected function writeCommandResult(OutputInterface $output, CommandResult $result): void
    {
        $output->writeln($result->message);

        if ($result->warning !== null) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('<comment>' . $result->warning . '</comment>');
        }
    }

    /** @throws StewartException */
    protected function parseTimeoutOption(InputInterface $input): Duration
    {
        return Duration::parse($this->nonEmptyStringOption($input, 'timeout') ?? self::DEFAULT_TIMEOUT);
    }
}
