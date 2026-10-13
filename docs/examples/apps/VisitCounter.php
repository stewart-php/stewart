<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Store\Store;

#[Automation(id: 'visit-counter')]
final readonly class VisitCounter implements App
{
    public function __construct(
        private HaContext $ha,
        private Store $store,
        private LoggerInterface $logger,
        private string $doorbell = 'binary_sensor.doorbell',
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges($this->doorbell)->whenChangedTo('on')->subscribe(function (): void {
            try {
                $this->store->increment('visits');
            } catch (StoreException $e) {
                if ($e->reason !== StoreError::NotIncrementable) {
                    throw $e;
                }

                $this->logger->warning('The visit count was not a number; starting again', ['exception' => $e]);
                $this->store->set('visits', 1);
            }
        });
    }

    public function dispose(): void {}
}
