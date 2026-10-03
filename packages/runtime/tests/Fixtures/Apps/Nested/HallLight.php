<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Apps\Nested;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'hall-light')]
final class HallLight implements App
{
    public function __construct(
        public readonly HaContext $ha,
        public readonly LoggerInterface $logger,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
