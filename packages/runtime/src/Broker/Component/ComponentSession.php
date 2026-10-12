<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Component;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Psr\Log\LoggerInterface;
use Stewart\Client\Exception\HaClientException;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Config\BackoffPolicy;
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
        private readonly BackoffPolicy $componentRelinkBackoff,
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
        $relinking = $this->relinking = new DeferredCancellation();

        async(function () use ($relinking): void {
            try {
                $this->relinkUntilSettled($relinking->getCancellation());
            } finally {
                if ($this->relinking === $relinking) {
                    $this->relinking = null;
                }
            }
        })->ignore();
    }

    public function markLinkLost(): void
    {
        $this->relinking?->cancel();
        $this->relinking = null;
        $this->link->markLinkLost();
    }

    private function relinkUntilSettled(Cancellation $stop): void
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                $this->openSession();
            } catch (HaClientException $e) {
                $this->logger->debug('Could not open the stewart integration session yet', ['exception' => $e, 'attempt' => $attempt]);
            }

            if ($stop->isRequested() || $this->hasSettled()) {
                return;
            }

            try {
                $this->deadlines->delay($this->componentRelinkBackoff->delayFor($attempt), $stop);
            } catch (CancelledException) {
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
