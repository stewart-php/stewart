<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Trigger;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Throwable;

use function Amp\async;

// One Home Assistant subscription per spec; ids are per connection, so every connect re-issues them all.
final class HaTriggerLink
{
    /** @var array<string, LiveTrigger> */
    private array $triggersByKey = [];

    private bool $linkReady = false;

    private int $connectionGeneration = 0;

    /** @var Closure(TriggerSpec, TriggerEvent): void */
    private Closure $onFired;

    /** @var Closure(TriggerSpec, string): void */
    private Closure $onRejected;

    public function __construct(
        private readonly HaClient $client,
        private readonly LoggerInterface $logger,
    ) {
        $this->onFired = static function (): void {};
        $this->onRejected = static function (): void {};
    }

    /** @param Closure(TriggerSpec, TriggerEvent): void $handler */
    public function onTriggerFired(Closure $handler): void
    {
        $this->onFired = $handler;
    }

    /** @param Closure(TriggerSpec, string): void $handler */
    public function onTriggerRejected(Closure $handler): void
    {
        $this->onRejected = $handler;
    }

    public function subscribeTrigger(TriggerSpec $spec): void
    {
        $key = $spec->getSharingKey();

        if (isset($this->triggersByKey[$key])) {
            return;
        }

        $live = new LiveTrigger($spec);
        $this->triggersByKey[$key] = $live;

        if (!$this->linkReady) {
            return;
        }

        $generation = $this->connectionGeneration;

        async(function () use ($live, $generation): void {
            try {
                $this->sendSubscribe($live, $generation);
            } catch (Throwable $e) {
                $this->logger->warning('Home Assistant trigger subscription failed; it is retried after the next reconnect', [
                    'platforms' => $live->spec->listPlatforms(),
                    'exception' => $e,
                ]);
            }
        })->ignore();
    }

    public function unsubscribeTrigger(TriggerSpec $spec): void
    {
        $key = $spec->getSharingKey();
        $live = $this->triggersByKey[$key] ?? null;
        unset($this->triggersByKey[$key]);

        if ($live?->haSubscriptionId === null || !$this->linkReady) {
            return;
        }

        $this->sendUnsubscribeInBackground($live->haSubscriptionId);
    }

    /** @throws HaClientException */
    public function resubscribeAll(): void
    {
        $generation = ++$this->connectionGeneration;
        $this->linkReady = true;

        foreach ($this->triggersByKey as $live) {
            $this->sendSubscribe($live, $generation);
        }
    }

    public function markLinkLost(): void
    {
        $this->linkReady = false;

        foreach ($this->triggersByKey as $live) {
            $live->forgetHaSubscriptionId();
        }
    }

    /** @throws HaClientException */
    private function sendSubscribe(LiveTrigger $live, int $generation): void
    {
        $key = $live->spec->getSharingKey();

        try {
            $haSubscriptionId = $this->client->subscribeTrigger(
                $live->spec->triggers,
                $live->spec->variables,
                fn(TriggerEvent $event) => $this->deliverFired($live, $event),
            );
        } catch (HaClientException $e) {
            if (!$e->reason->isCommandRefusal()) {
                throw $e;
            }

            if (($this->triggersByKey[$key] ?? null) === $live) {
                unset($this->triggersByKey[$key]);
                ($this->onRejected)($live->spec, $e->getMessage());
            }

            return;
        }

        if ($generation !== $this->connectionGeneration) {
            return;
        }

        if (($this->triggersByKey[$key] ?? null) !== $live) {
            $this->sendUnsubscribeInBackground($haSubscriptionId);

            return;
        }

        $live->recordHaSubscriptionId($haSubscriptionId);
    }

    private function deliverFired(LiveTrigger $live, TriggerEvent $event): void
    {
        if (($this->triggersByKey[$live->spec->getSharingKey()] ?? null) !== $live) {
            return;
        }

        ($this->onFired)($live->spec, $event);
    }

    private function sendUnsubscribeInBackground(int $haSubscriptionId): void
    {
        async(function () use ($haSubscriptionId): void {
            try {
                $this->client->unsubscribeEvents($haSubscriptionId);
            } catch (Throwable $e) {
                $this->logger->debug('Home Assistant trigger unsubscribe failed', ['subscription' => $haSubscriptionId, 'exception' => $e]);
            }
        })->ignore();
    }
}
