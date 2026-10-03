<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Closure;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

final class LoopErrorLogger
{
    /** @var (Closure(Throwable): void)|null */
    private ?Closure $previous = null;

    private bool $installed = false;

    public function __construct(private readonly LoggerInterface $logger) {}

    public function install(): void
    {
        if ($this->installed) {
            return;
        }

        $this->previous = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler($this->logError(...));
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

    private function logError(Throwable $error): void
    {
        $this->logger->critical('Unhandled error in the event loop', ['exception' => $error]);
    }
}
