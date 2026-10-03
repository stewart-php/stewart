<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;

final readonly class NeedsUnregisteredService implements App
{
    public function __construct(public RecordingPoolListener $unregistered) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
