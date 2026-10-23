<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\App;
use Stewart\Contracts\AppTemplate;
use Stewart\Contracts\Store\Store;

#[AppTemplate]
final readonly class RoomCounterTemplate implements App
{
    public function __construct(
        private Store $own,
        private string $room,
    ) {}

    public function initialize(): void
    {
        $this->own->set('room', $this->room);
    }
}
