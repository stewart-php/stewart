<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\Cancellation;
use Amp\CancelledException;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stewart\Client\Exception\HaClientException;
use Stewart\Runtime\Config\BackoffPolicy;
use Stewart\Support\Time\Deadlines;
use Throwable;

final readonly class Reconnector
{
    public function __construct(
        private BackoffPolicy $reconnectBackoff,
        private LoggerInterface $logger,
        private Deadlines $deadlines,
    ) {}

    /** @param Closure(): void $attempt */
    public function retryUntilConnected(Closure $attempt, Cancellation $stop): void
    {
        for ($failures = 0; !$stop->isRequested(); ++$failures) {
            try {
                $attempt();

                return;
            } catch (Throwable $e) {
                if ($e instanceof HaClientException && $e->reason->isFatal()) {
                    throw $e;
                }

                $this->logger->log(
                    $failures === 0 ? LogLevel::ERROR : LogLevel::WARNING,
                    'Could not reach Home Assistant',
                    ['exception' => $e, 'attempt' => $failures + 1],
                );
            }

            try {
                $stop->throwIfRequested();

                $delay = $this->reconnectBackoff->delayFor($failures + 1);

                $this->logger->info('Retrying Home Assistant', ['in' => (string) $delay, 'attempt' => $failures + 2]);
                $this->deadlines->delay($delay, $stop);
            } catch (CancelledException) {
                return;
            }
        }
    }
}
