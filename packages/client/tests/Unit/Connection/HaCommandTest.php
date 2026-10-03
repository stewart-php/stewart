<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Connection\Command\Authenticate;
use Stewart\Client\Connection\Command\CallService;
use Stewart\Client\Connection\Command\GetStates;
use Stewart\Client\Connection\Command\SubscribeEvents;
use Stewart\Contracts\Service\ServiceTarget;

#[CoversClass(CallService::class)]
#[CoversClass(SubscribeEvents::class)]
#[CoversClass(Authenticate::class)]
#[CoversClass(GetStates::class)]
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
}
