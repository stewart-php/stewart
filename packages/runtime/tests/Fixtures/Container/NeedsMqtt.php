<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Mqtt\Mqtt;

final readonly class NeedsMqtt implements App
{
    public function __construct(public Mqtt $mqtt) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
