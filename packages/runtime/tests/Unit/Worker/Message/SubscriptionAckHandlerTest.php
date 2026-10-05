<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Contracts\Subscription;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Runtime\Worker\Message\SubscriptionAckHandler;

#[CoversClass(SubscriptionAckHandler::class)]
final class SubscriptionAckHandlerTest extends TestCase
{
    public function testRejectionDeactivatesSubscription(): void
    {
        $dispatcher = RecordingDispatchListener::createDispatcher('w0', 10);
        $subscription = self::registerTopicSubscription($dispatcher);

        new SubscriptionAckHandler(new NullLogger(), $dispatcher)->handle(
            new SubscriptionAck(SubscriptionId::fromString($subscription->getId()), false, 'Invalid trigger'),
        );

        self::assertFalse($subscription->isActive());
    }

    public function testAcceptanceKeepsSubscription(): void
    {
        $dispatcher = RecordingDispatchListener::createDispatcher('w0', 10);
        $subscription = self::registerTopicSubscription($dispatcher);

        new SubscriptionAckHandler(new NullLogger(), $dispatcher)->handle(
            new SubscriptionAck(SubscriptionId::fromString($subscription->getId()), true, null),
        );

        self::assertTrue($subscription->isActive());
    }

    private static function registerTopicSubscription(LocalDispatcher $dispatcher): Subscription
    {
        $scope = new SubscriptionScope();
        $subscription = $dispatcher->register(ResourceScope::forApp(new AppId('app')), SubscriptionKind::Topic, Selector::any(), $scope, static function (): void {});
        $scope->attachedTo($subscription);

        return $subscription;
    }
}
