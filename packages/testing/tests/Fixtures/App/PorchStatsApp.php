<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Store\GlobalStore;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;

#[Automation(id: 'porch-stats')]
final class PorchStatsApp implements App
{
    public function __construct(
        private readonly Store $own,
        #[GlobalStore]
        private readonly Store $shared,
        #[PeerStore('hall-light')]
        private readonly ReadableStore $hallLight,
        public readonly FakeEntities $entities,
        private readonly ?Notifier $notifier = null,
    ) {}

    public function initialize(): void
    {
        $motions = $this->hallLight->getInt('motions') ?? 0;
        $this->own->set('hall_motions_seen', $motions);
        $this->shared->set('porch_ready', true);
        $this->notifier?->notify(\sprintf('%d motions in the hall', $motions));
    }
}
