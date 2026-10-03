<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Store\Store;

#[Automation(id: 'run-counter')]
final readonly class RunCounter implements App
{
    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
        private Store $store,
        private string $watch,
    ) {}

    public function initialize(): void
    {
        $this->logger->info('Run counter starting', [
            'run' => $this->store->increment('runs'),
            'changes_seen_before' => $this->store->getInt('changes_seen') ?? 0,
        ]);

        $this->ha->watchStateChanges($this->watch)->subscribe(function (StateChange $change): void {
            $this->logger->info('Change counted', ['changes_seen' => $this->store->increment('changes_seen')]);
        });
    }

    public function dispose(): void {}
}
