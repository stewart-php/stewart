<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Client\ControlTargetResolver;
use Stewart\Runtime\Control\Client\ReadinessCheck;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: self::NAME, description: 'Show what the running daemon is doing, once')]
final class StatusCommand extends StewartCommand
{
    public const string NAME = 'status';

    private const string DEFAULT_TIMEOUT = '5s';

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly ControlClient $client,
        private readonly ControlTargetResolver $targets,
        private readonly StatusRenderer $renderer,
        private readonly FrameCodec $codec,
        private readonly ReadinessCheck $readiness,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('address', null, InputOption::VALUE_REQUIRED, 'Where the daemon listens, such as unix://var/run/stewart.sock or tcp://host:port; defaults to control.listen')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'The control token; defaults to control.token, usually STEWART_CONTROL__TOKEN')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'How long to wait for the daemon, like 5s', self::DEFAULT_TIMEOUT)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the snapshot as the wire JSON instead of tables')
            ->addOption('probe', null, InputOption::VALUE_REQUIRED, 'liveness or readiness: print one line and exit 0 or 1, for health checks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $probe = $this->findProbeOption($input);
            $timeout = Duration::parse($this->nonEmptyStringOption($input, 'timeout') ?? self::DEFAULT_TIMEOUT);
            $snapshot = $this->client->fetchSnapshot($this->resolveControlTarget($input), $timeout);
        } catch (StewartException|StreamException|CancelledException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($probe !== null) {
            return $this->reportProbe($output, $probe, $snapshot);
        }

        if ((bool) $input->getOption('json')) {
            $output->write($this->codec->encodeFrame(new SnapshotFrame($snapshot)));

            return Command::SUCCESS;
        }

        $this->renderer->render($output, $snapshot);

        return Command::SUCCESS;
    }

    private function reportProbe(OutputInterface $output, ProbeKind $probe, RuntimeSnapshot $snapshot): int
    {
        if ($probe === ProbeKind::Liveness) {
            $output->writeln('alive');

            return Command::SUCCESS;
        }

        $verdict = $this->readiness->assessSnapshot($snapshot);
        $output->writeln($verdict->describeVerdict());

        return $verdict->isReady() ? Command::SUCCESS : Command::FAILURE;
    }

    /** @throws ConfigurationException */
    private function findProbeOption(InputInterface $input): ?ProbeKind
    {
        $probe = $this->nonEmptyStringOption($input, 'probe');

        return $probe === null ? null : ProbeKind::parse($probe);
    }

    private function resolveControlTarget(InputInterface $input): ControlTarget
    {
        $path = $this->findConfigOption($input);

        return $this->targets->resolveTarget(
            $this->nonEmptyStringOption($input, 'address'),
            $this->nonEmptyStringOption($input, 'token'),
            fn(): ControlConfig => $this->config->loadConfig($path)->control,
        );
    }
}
