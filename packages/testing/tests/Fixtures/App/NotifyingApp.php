<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: 'notifying')]
final readonly class NotifyingApp implements App
{
    public function __construct(private Notifier $notifier) {}

    public function initialize(): void
    {
        $this->notifier->notify('started');
    }

    public function dispose(): void {}
}
