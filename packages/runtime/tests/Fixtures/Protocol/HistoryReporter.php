<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Time\Duration;

#[Automation(id: 'history-reporter')]
final readonly class HistoryReporter implements App
{
    public const string ENTITY = 'light.hall';

    public function __construct(
        private HaContext $ha,
        private LoggerInterface $logger,
    ) {}

    public function initialize(): void
    {
        $this->ha->watchStateChanges(self::ENTITY)->subscribe(function (): void {
            $history = $this->ha->getEntity(self::ENTITY)->getHistory(HistoryQuery::lastFor(Duration::minutes(30)));

            $this->logger->info('History read', ['changes' => $history->countChanges(), 'was_on' => $history->hasBeenIn('on')]);
        });
    }

    public function dispose(): void {}
}
