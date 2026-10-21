<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Broker\DaemonLaunchOptions;
use Stewart\Runtime\Time\RepeatingTimer;
use Throwable;

final class SourceWatcher
{
    private const int SCAN_INTERVAL_SECONDS = 1;

    private ?RepeatingTimer $timer = null;

    private ?SourcePaths $paths = null;

    private ?SourceChangeTracker $sources = null;

    /** @var array<string, SourceChangeTracker> */
    private array $restartOnlyFiles = [];

    public function __construct(
        private readonly DaemonLaunchOptions $launch,
        private readonly SourcePathFinder $pathFinder,
        private readonly SourceScanner $scanner,
        private readonly ReloadCoordinator $reloads,
        private readonly Timers $timers,
        private readonly LoggerInterface $logger,
    ) {}

    public function startWatching(): void
    {
        if (!$this->launch->watch || $this->timer !== null) {
            return;
        }

        $this->paths = $this->pathFinder->findSourcePaths();
        $this->sources = new SourceChangeTracker($this->scanner->scanFingerprint($this->paths->reloading));
        $this->restartOnlyFiles = [];

        foreach ($this->paths->restartOnly as $file) {
            $this->restartOnlyFiles[$file] = new SourceChangeTracker($this->scanner->scanFingerprint([$file]));
        }

        $this->timer = new RepeatingTimer(
            $this->timers,
            Duration::seconds(self::SCAN_INTERVAL_SECONDS),
            $this->scanForChanges(...),
            fn(Throwable $e) => $this->logger->warning('Looking for changed files failed', ['exception' => $e]),
        );
        $this->timer->start();
        $this->logger->info('Watching for changes; a saved edit reloads the workers', ['paths' => $this->paths->reloading]);
    }

    public function stopWatching(): void
    {
        $this->timer?->stop();
        $this->timer = null;
    }

    public function scanForChanges(): void
    {
        if ($this->paths === null || $this->sources === null) {
            return;
        }

        $settled = $this->sources->findSettledChange($this->scanner->scanFingerprint($this->paths->reloading));

        // Only a reload that started takes the change; one refused now is asked for again on the next scan.
        if ($settled !== null && $this->reloads->requestReload(ReloadTrigger::Watch) === ReloadRequestOutcome::Started) {
            $this->sources->markApplied($settled);
        }

        foreach ($this->restartOnlyFiles as $file => $tracker) {
            $changed = $tracker->findSettledChange($this->scanner->scanFingerprint([$file]));

            if ($changed !== null) {
                $tracker->markApplied($changed);
                $this->logger->warning('A changed file needs a restart of Stewart; a reload does not apply it', ['file' => $file]);
            }
        }
    }
}
