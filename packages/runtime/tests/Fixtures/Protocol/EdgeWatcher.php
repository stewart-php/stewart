<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'edge-watcher')]
final readonly class EdgeWatcher implements App
{
    public const string TOPIC = 'watch.triggered';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
        private string $watch,
        private ?string $light = null,
    ) {}

    public function initialize(): void
    {
        $current = $this->ha->getState($this->watch);

        $this->logger->info('Watcher starting', [
            'current_state' => $current?->state,
            'entities_visible' => \count($this->ha->listStates()),
        ]);

        $this->ha->watchStateChanges($this->watch)->whenChangedTo('on', Duration::milliseconds(10))->subscribe($this->recordChange(...));
    }

    public function dispose(): void {}

    private function recordChange(StateChange $change): void
    {
        $this->logger->info('Watched entity changed', ['to' => $change->to?->state]);

        if ($this->light !== null) {
            try {
                $this->ha->callService('light', 'turn_on', target: ServiceTarget::forEntities($this->light));
                $this->logger->info('Service call finished', ['success' => true]);
            } catch (ServiceCallException $e) {
                $this->logger->info('Service call finished', ['success' => false, 'exception' => $e]);
            }
        }

        $this->ha->publish(self::TOPIC, ['state' => $change->to?->state]);
    }
}
