<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Component;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Client\Component\ComponentCommand;
use Stewart\Client\Component\ComponentProtocol;
use Stewart\Client\Component\ComponentSessionEvent;
use Stewart\Client\Component\ComponentSessionRequest;
use Stewart\Client\Component\ComponentVersion;
use Stewart\Client\Component\SessionEnded;
use Stewart\Client\Component\SessionReplaced;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\StewartVersion;

// Session subscriptions live as long as one connection, so every connect detects the component again.
final class ComponentLink
{
    private int $connectionGeneration = 0;

    /** @var Closure(ComponentCommand): void */
    private Closure $onCommand;

    /** @var Closure(): void */
    private Closure $onSessionEnded;

    public function __construct(
        private readonly HaClient $client,
        private readonly LoggerInterface $logger,
        private readonly ComponentTracker $tracker,
        private readonly ExposeConfig $expose,
        private readonly StewartVersion $stewartVersion,
    ) {
        $this->onCommand = static function (): void {};
        $this->onSessionEnded = static function (): void {};
    }

    /** @param Closure(ComponentCommand): void $handler */
    public function onCommand(Closure $handler): void
    {
        $this->onCommand = $handler;
    }

    /** @param Closure(): void $handler */
    public function onSessionEnded(Closure $handler): void
    {
        $this->onSessionEnded = $handler;
    }

    /** @throws HaClientException */
    public function establishLink(): void
    {
        $generation = ++$this->connectionGeneration;
        $version = null;

        try {
            $version = $this->client->findComponentVersion();

            if ($version === null) {
                if ($this->recordDetectedState(ComponentState::Missing, null)) {
                    $this->logger->info('The stewart Home Assistant integration is not installed, so apps cannot expose entities');
                }

                return;
            }

            if (!$version->isCompatible()) {
                if ($this->recordDetectedState(ComponentState::ProtocolMismatch, $version)) {
                    $this->logger->warning('The stewart Home Assistant integration speaks another protocol; update it or Stewart so they match', [
                        'component_version' => $version->componentVersion,
                        'component_protocol' => $version->protocol,
                        'stewart_protocol' => ComponentProtocol::VERSION,
                    ]);
                }

                return;
            }

            $this->client->subscribeComponentSession(
                new ComponentSessionRequest($this->expose->instance, $this->stewartVersion->value, $this->expose->commandTimeout),
                fn(ComponentSessionEvent $event) => $this->handleSessionEvent($event, $generation),
            );
        } catch (HaClientException $e) {
            if (!$e->reason->isCommandRefusal()) {
                throw $e;
            }

            if ($this->recordDetectedState(ComponentState::Refused, $version)) {
                $this->logger->warning('Home Assistant refused the stewart integration session', ['exception' => $e]);
            }

            return;
        }

        $this->recordDetectedState(ComponentState::Active, $version);
        $this->logger->info('Opened the stewart Home Assistant integration session', [
            'component_version' => $version->componentVersion,
            'instance' => (string) $this->expose->instance,
        ]);
    }

    public function markLinkLost(): void
    {
        ++$this->connectionGeneration;
        $this->tracker->recordStateKeepingVersion(ComponentState::Unchecked);
    }

    // A relink retries while the component is missing; only the first of those attempts logs.
    private function recordDetectedState(ComponentState $state, ?ComponentVersion $version): bool
    {
        $changed = $this->tracker->detection->state !== $state;
        $this->tracker->recordState($state, $version);

        return $changed;
    }

    private function handleSessionEvent(ComponentSessionEvent $event, int $generation): void
    {
        if ($generation !== $this->connectionGeneration) {
            return;
        }

        if ($event instanceof ComponentCommand) {
            ($this->onCommand)($event);

            return;
        }

        if ($event instanceof SessionEnded) {
            ($this->onSessionEnded)();

            return;
        }

        if (!$event instanceof SessionReplaced) {
            return;
        }

        $this->logger->warning('Another Stewart took over the stewart integration session; give each daemon its own expose.instance', [
            'instance' => (string) $this->expose->instance,
        ]);
        $this->tracker->recordStateKeepingVersion(ComponentState::Replaced);
    }
}
