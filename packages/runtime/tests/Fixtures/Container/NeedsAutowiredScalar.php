<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class NeedsAutowiredScalar implements App
{
    public function __construct(
        #[Autowire(param: 'stewart.worker_id')]
        public int $workerId,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
