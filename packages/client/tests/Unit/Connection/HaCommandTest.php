<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Connection\Command\Authenticate;
use Stewart\Client\Connection\Command\CallService;
use Stewart\Client\Connection\Command\GetHistoryDuringPeriod;
use Stewart\Client\Connection\Command\GetStates;
use Stewart\Client\Connection\Command\SubscribeEvents;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Time\Instant;

#[CoversClass(CallService::class)]
#[CoversClass(SubscribeEvents::class)]
#[CoversClass(Authenticate::class)]
#[CoversClass(GetStates::class)]
#[CoversClass(GetHistoryDuringPeriod::class)]
final class HaCommandTest extends TestCase
{
    public function testServiceCallOmitsEmptyParts(): void
    {
        self::assertSame(
            ['type' => 'call_service', 'domain' => 'light', 'service' => 'turn_on'],
            new CallService('light', 'turn_on', [], ServiceTarget::forEntities())->toMessage(),
        );
    }

    public function testServiceCallCarriesDataTargetAndResponseFlag(): void
    {
        self::assertSame(
            [
                'type' => 'call_service',
                'domain' => 'light',
                'service' => 'turn_on',
                'service_data' => ['brightness' => 100],
                'target' => ['entity_id' => ['light.hall']],
                'return_response' => true,
            ],
            new CallService('light', 'turn_on', ['brightness' => 100], ServiceTarget::forEntities('light.hall'), true)->toMessage(),
        );
    }

    public function testServiceCallDescribesDomainAndService(): void
    {
        self::assertSame('call_service light.turn_on', new CallService('light', 'turn_on')->describe());
    }

    public function testHistoryWithoutAttributesAsksMinimalRows(): void
    {
        $command = $this->createHistoryCommand(HistoryDetail::StateChanges);

        self::assertSame(
            [
                'type' => 'history/history_during_period',
                'start_time' => '2026-10-04T11:00:00.000000Z',
                'end_time' => '2026-10-04T12:00:00.000000Z',
                'entity_ids' => ['light.hall'],
                'include_start_time_state' => true,
                'significant_changes_only' => true,
                'minimal_response' => true,
                'no_attributes' => true,
            ],
            $command->toMessage(),
        );
        self::assertSame('history/history_during_period light.hall', $command->describe());
    }

    public function testHistoryWithAttributesAsksFullRows(): void
    {
        $message = $this->createHistoryCommand(HistoryDetail::StateChangesWithAttributes)->toMessage();

        self::assertFalse($message['minimal_response']);
        self::assertFalse($message['no_attributes']);
        self::assertTrue($message['significant_changes_only']);
    }

    public function testHistoryOfAllChangesAsksAttributeOnlyRows(): void
    {
        $message = $this->createHistoryCommand(HistoryDetail::AllChanges)->toMessage();

        self::assertFalse($message['significant_changes_only']);
        self::assertFalse($message['no_attributes']);
    }

    public function testSubscriptionWithoutTypeListensToEverything(): void
    {
        $subscription = new SubscribeEvents();

        self::assertSame(['type' => 'subscribe_events'], $subscription->toMessage());
        self::assertSame('subscribe_events "*"', $subscription->describe());
    }

    public function testSubscriptionWithTypeListensToThatType(): void
    {
        $subscription = new SubscribeEvents('zha_event');

        self::assertSame(['type' => 'subscribe_events', 'event_type' => 'zha_event'], $subscription->toMessage());
        self::assertSame('subscribe_events "zha_event"', $subscription->describe());
    }

    public function testParameterlessCommandSendsOnlyItsType(): void
    {
        self::assertSame(['type' => 'get_states'], new GetStates()->toMessage());
    }

    public function testAuthenticationCarriesTheToken(): void
    {
        self::assertSame(['type' => 'auth', 'access_token' => 't'], new Authenticate('t')->toMessage());
    }

    private function createHistoryCommand(HistoryDetail $detail): GetHistoryDuringPeriod
    {
        return new GetHistoryDuringPeriod(
            new EntityId('light.hall'),
            new HistoryWindow(Instant::fromIso('2026-10-04T11:00:00Z'), Instant::fromIso('2026-10-04T12:00:00Z')),
            $detail,
        );
    }
}
