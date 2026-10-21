<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;

final readonly class ReloadProbe implements App
{
    public function __construct(
        private LoggerInterface $logger,
        private string $script,
    ) {}

    public function initialize(): void
    {
        $this->logger->info('Probe loaded', ['version' => require $this->script]);
    }
}
