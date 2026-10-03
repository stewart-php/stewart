<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Closure;
use Revolt\EventLoop;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

final class LoopErrorReporter
{
    private const string LOOP_ORIGIN = 'event loop';

    /** @var (Closure(Throwable): void)|null */
    private ?Closure $previous = null;

    private bool $installed = false;

    public function __construct(private readonly AppFailureReporter $failures) {}

    public function install(): void
    {
        if ($this->installed) {
            return;
        }

        $this->previous = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler($this->reportError(...));
        $this->installed = true;
    }

    public function restorePrevious(): void
    {
        if (!$this->installed) {
            return;
        }

        EventLoop::setErrorHandler($this->previous);
        $this->previous = null;
        $this->installed = false;
    }

    private function reportError(Throwable $error): void
    {
        $this->failures->report(ResourceScope::shared(), AppFailurePhase::Handler, $error, self::LOOP_ORIGIN);
    }
}
