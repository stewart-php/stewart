<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class SupervisionConfig
{
    public function __construct(
        public int $restartAttempts,
        public Duration $restartWindow,
        public BackoffPolicy $restartBackoff,
        public OptionalDuration $pingInterval,
        public Duration $lagThreshold,
        public int $unresponsiveAfter,
        public OptionalDuration $initializeTimeout,
        public OptionalDuration $readyTimeout,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $supervision): self
    {
        $oneMillisecond = Duration::milliseconds(1);
        $restartAttempts = $supervision->readInt('restart_attempts');
        $restartWindow = $supervision->readDuration('restart_window', $oneMillisecond);
        $restartBackoff = $supervision->readBackoff('restart_');
        $restartBackoffTotal = $restartBackoff->sumDelaysUpTo($restartAttempts);

        if (!$restartWindow->isLongerThan($restartBackoffTotal)) {
            throw ConfigurationException::restartWindowTooShort((string) $restartWindow, $restartAttempts, (string) $restartBackoffTotal);
        }

        return new self(
            restartAttempts: $restartAttempts,
            restartWindow: $restartWindow,
            restartBackoff: $restartBackoff,
            pingInterval: $supervision->readOptionalDuration('ping_interval', $oneMillisecond),
            lagThreshold: $supervision->readDuration('lag_threshold', $oneMillisecond),
            unresponsiveAfter: $supervision->readInt('unresponsive_after'),
            initializeTimeout: $supervision->readOptionalDuration('initialize_timeout', $oneMillisecond),
            readyTimeout: $supervision->readOptionalDuration('ready_timeout', $oneMillisecond),
        );
    }

    public function killsUnresponsiveWorkers(): bool
    {
        return $this->unresponsiveAfter > 0;
    }
}
