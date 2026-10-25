<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Component;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Psr\Log\LoggerInterface;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Support\Time\Deadlines;

use function Amp\async;

final class ComponentSession
{
    private ?DeferredCancellation $relinking = null;

    public function __construct(
        private readonly ComponentLink $link,
        private readonly ExposureLink $exposures,
        private readonly ExposureReconciler $reconciler,
        private readonly ComponentTracker $tracker,
        private readonly StewartConfig $config,
        private readonly Deadlines $deadlines,
        private readonly LoggerInterface $logger,
    ) {
        $link->onCommand($exposures->receiveCommand(...));
        $link->onSessionEnded($this->relinkAfterSessionEnded(...));
        $exposures->onSessionLost($this->relinkAfterSessionEnded(...));
    }

    /** @throws HaClientException */
    public function openSession(): void
    {
        $this->link->establishLink();
        $this->exposures->replayAll();
        $this->reconciler->reconcileIfDue();
    }

    public function relinkAfterSessionEnded(): void
    {
        // Also when a relink runs, so a session lost during its replay keeps it going.
        $this->link->markLinkLost();

        if ($this->relinking !== null) {
            return;
        }

        $this->logger->info('The stewart Home Assistant integration session ended; opening a new one');
        $this->startRelinking(0);
    }

    // Home Assistant answers before it has loaded the integration, so a connect can find it missing.
    public function startRelinkingUnlessSettled(): void
    {
        if ($this->relinking !== null || $this->hasSettled()) {
            return;
        }

        $this->startRelinking(1);
    }

    public function markLinkLost(): void
    {
        $this->relinking?->cancel();
        $this->relinking = null;
        $this->link->markLinkLost();
    }

    private function startRelinking(int $attemptsMade): void
    {
        $relinking = $this->relinking = new DeferredCancellation();

        async(function () use ($relinking, $attemptsMade): void {
            try {
                $this->relinkUntilSettled($attemptsMade, $relinking->getCancellation());
            } finally {
                if ($this->relinking === $relinking) {
                    $this->relinking = null;
                }
            }
        })->ignore();
    }

    private function relinkUntilSettled(int $attemptsMade, Cancellation $stop): void
    {
        for ($attempt = $attemptsMade + 1; ; ++$attempt) {
            if ($attempt > 1) {
                try {
                    $this->deadlines->delay($this->config->reconnectBackoff->delayFor($attempt - 1), $stop);
                } catch (CancelledException) {
                    return;
                }
            }

            try {
                $this->openSession();
            } catch (HaClientException $e) {
                // Any other failure means the connection is gone; the reconnect relinks.
                if ($e->reason !== HaClientError::CommandTimedOut) {
                    return;
                }

                $this->logger->debug('Could not open the stewart integration session yet', ['exception' => $e, 'attempt' => $attempt]);
            }

            if ($stop->isRequested() || $this->hasSettled()) {
                return;
            }
        }
    }

    // A missing component is usually one that Home Assistant is reloading.
    private function hasSettled(): bool
    {
        return match ($this->tracker->detection->state) {
            ComponentState::Unchecked, ComponentState::Missing => false,
            ComponentState::ProtocolMismatch, ComponentState::Refused, ComponentState::Replaced, ComponentState::Active => true,
        };
    }
}
