<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\NullCancellation;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Control\Client\ControlClient;
use Stewart\Runtime\Control\Client\ControlTarget;
use Stewart\Runtime\Control\Client\ControlTargetResolver;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Status\AppStatus;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Health\ProbeKind;
use Stewart\Runtime\Health\ReadinessCheck;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Support\Text\ClosestNameFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: self::NAME, description: 'Show what the running daemon is doing')]
final class StatusCommand extends ControlCommand
{
    public const string NAME = 'status';

    private const string DEFAULT_WATCH_INTERVAL = '2s';

    // Cursor home, then clear to the end, as watch(1) redraws; the output is often taller than the terminal.
    private const string CLEAR_SCREEN = "\e[H\e[J";

    public function __construct(
        ConfigLoader $config,
        ControlTargetResolver $targets,
        private readonly ControlClient $client,
        private readonly StatusRenderer $renderer,
        private readonly FrameCodec $codec,
        private readonly ReadinessCheck $readiness,
        private readonly StatusWatch $watch,
        private readonly ClosestNameFinder $names,
    ) {
        parent::__construct($config, $targets);
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('apps', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Show only these apps, with their subscriptions and schedules')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the snapshot as the wire JSON instead of tables')
            ->addOption('probe', null, InputOption::VALUE_REQUIRED, 'liveness or readiness: print one line and exit 0 or 1, for health checks')
            ->addOption('watch', null, InputOption::VALUE_OPTIONAL, 'Redraw every interval, like 5s, until Ctrl-C; every 2s when given alone', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $appIds = $this->readAppIdsArgument($input);
            $interval = $this->findWatchInterval($input);
            $probe = $this->findProbeOption($input, $appIds, $interval);
            $target = $this->resolveControlTarget($input);
            $timeout = $this->parseTimeoutOption($input);
            $json = (bool) $input->getOption('json');
            $view = $appIds === [] ? StatusView::Overview : StatusView::AppDetail;

            if ($interval !== null) {
                $this->watch->watchStatus(
                    fn(): RuntimeSnapshot => $this->fetchFocusedSnapshot($target, $timeout, $appIds),
                    fn(RuntimeSnapshot $snapshot) => $this->drawWatchFrame($output, $snapshot, $json, $view, $interval),
                    fn(string $outage) => $this->drawOutage($output, $outage, $json, $interval),
                    $interval,
                    new NullCancellation(),
                );

                return Command::SUCCESS;
            }

            $snapshot = $this->fetchFocusedSnapshot($target, $timeout, $appIds);
        } catch (StewartException|StreamException|CancelledException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($probe !== null) {
            return $this->reportProbe($output, $probe, $snapshot);
        }

        $this->writeSnapshot($output, $snapshot, $json, $view);

        return Command::SUCCESS;
    }

    /**
     * @param list<AppId> $appIds
     * @throws StewartException|Throwable
     */
    private function fetchFocusedSnapshot(ControlTarget $target, Duration $timeout, array $appIds): RuntimeSnapshot
    {
        $snapshot = $this->client->fetchSnapshot($target, $timeout);

        if ($appIds === []) {
            return $snapshot;
        }

        $knownIds = array_values(array_filter(
            array_map(static fn(AppStatus $app): string => $app->id, $snapshot->apps),
            static fn(string $id): bool => ResourceScope::tryFromWireValue($id)?->isShared() !== true,
        ));

        foreach ($appIds as $appId) {
            if (!\in_array($appId->value, $knownIds, true)) {
                throw AppException::unknown($appId, $this->names->findClosestName($appId->value, $knownIds));
            }
        }

        return $snapshot->withOnlyApps(array_map(static fn(AppId $appId): string => $appId->value, $appIds));
    }

    private function writeSnapshot(OutputInterface $output, RuntimeSnapshot $snapshot, bool $json, StatusView $view): void
    {
        if ($json) {
            $output->write($this->codec->encodeFrame(new SnapshotFrame($snapshot)));

            return;
        }

        $this->renderer->render($output, $snapshot, $view);
    }

    private function drawWatchFrame(OutputInterface $output, RuntimeSnapshot $snapshot, bool $json, StatusView $view, Duration $interval): void
    {
        if (!$json) {
            $this->clearScreen($output);
            $output->writeln(\sprintf('<comment>Every %s; Ctrl-C stops.</comment>', $interval));
            $output->writeln('');
        }

        $this->writeSnapshot($output, $snapshot, $json, $view);
    }

    private function drawOutage(OutputInterface $output, string $outage, bool $json, Duration $interval): void
    {
        $notice = \sprintf('Stewart is not reachable, retrying every %s: %s', $interval, $outage);

        if ($json) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($notice);

            return;
        }

        $this->clearScreen($output);
        $output->writeln('<comment>' . $notice . '</comment>');
    }

    private function clearScreen(OutputInterface $output): void
    {
        if ($output->isDecorated()) {
            $output->write(self::CLEAR_SCREEN);
        }
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

    /**
     * @return list<AppId>
     * @throws IdentifierException
     */
    private function readAppIdsArgument(InputInterface $input): array
    {
        $ids = $input->getArgument('apps');

        return \is_array($ids) ? array_map(static fn(string $id): AppId => new AppId($id), array_values(array_filter($ids, \is_string(...)))) : [];
    }

    /** @throws StewartException */
    private function findWatchInterval(InputInterface $input): ?Duration
    {
        $watch = $input->getOption('watch');

        if ($watch === false) {
            return null;
        }

        $raw = \is_string($watch) && $watch !== '' ? $watch : self::DEFAULT_WATCH_INTERVAL;
        $interval = Duration::parse($raw);

        return $interval->isLongerThan(Duration::zero()) ? $interval : throw ConfigurationException::valueInvalid($raw, 'a --watch interval longer than 0s');
    }

    /**
     * @param list<AppId> $appIds
     * @throws ConfigurationException
     */
    private function findProbeOption(InputInterface $input, array $appIds, ?Duration $interval): ?ProbeKind
    {
        $probe = $this->nonEmptyStringOption($input, 'probe');

        if ($probe === null) {
            return null;
        }

        if ($appIds !== [] || $interval !== null) {
            throw ConfigurationException::createForReason(ConfigurationError::OptionsConflict, option: '--probe', conflicting: 'app IDs or --watch');
        }

        return ProbeKind::parse($probe);
    }
}
