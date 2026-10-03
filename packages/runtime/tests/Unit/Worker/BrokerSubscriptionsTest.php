<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\BrokerSubscriptions;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(BrokerSubscriptions::class)]
final class BrokerSubscriptionsTest extends TestCase
{
    public function testTopicSubscriptionIsAnnouncedAndWithdrawn(): void
    {
        $transport = new NullTransport();
        $subscriptions = new BrokerSubscriptions($transport, new NullLogger());
        $topic = self::createSubscription(SubscriptionKind::Topic, 'watch.*');

        $subscriptions->subscriptionRegistered($topic);
        $subscriptions->subscriptionCancelled($topic);

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(Subscribe::class, $transport->sent[0]);
        self::assertSame('w0:1', $transport->sent[0]->subscriptionId->value);
        self::assertEquals(new Unsubscribe(new SubscriptionId('w0:1')), $transport->sent[1]);
    }

    public function testStateSubscriptionStaysInTheWorker(): void
    {
        $transport = new NullTransport();
        $subscriptions = new BrokerSubscriptions($transport, new NullLogger());
        $state = self::createSubscription(SubscriptionKind::StateChange, 'light.kitchen');

        $subscriptions->subscriptionRegistered($state);
        $subscriptions->subscriptionCancelled($state);

        self::assertSame([], $transport->sent);
    }

    public function testWithdrawalOnAClosedChannelIsLogged(): void
    {
        $transport = new NullTransport();
        $logger = new RecordingLogger();
        $subscriptions = new BrokerSubscriptions($transport, $logger);
        $topic = self::createSubscription(SubscriptionKind::Topic, 'watch.*');

        $subscriptions->subscriptionRegistered($topic);
        $transport->close();
        $subscriptions->subscriptionCancelled($topic);

        self::assertSame(['Could not unsubscribe; the broker channel is closed'], $logger->listMessagesAt('debug'));
    }

    private static function createSubscription(SubscriptionKind $kind, string $selector): RegisteredSubscription
    {
        return new RegisteredSubscription(new SubscriptionId('w0:1'), ResourceScope::forApp(new AppId('app')), $kind, Selector::fromSpec($selector), new SubscriptionScope(), static function (): void {});
    }
}
