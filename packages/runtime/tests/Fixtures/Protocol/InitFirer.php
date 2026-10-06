<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'init-firer')]
final readonly class InitFirer implements App
{
    public const string EVENT_TYPE = 'doorbell_pressed';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $context = $this->ha->fireEvent(self::EVENT_TYPE, ['button' => 'front']);

        $this->logger->info('Initialize fire finished', ['context' => $context->id]);
    }

    public function dispose(): void {}
}
