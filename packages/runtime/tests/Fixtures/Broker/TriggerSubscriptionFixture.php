<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;

final class TriggerSubscriptionFixture
{
    public static function createSubscription(string $id, int $workerId, HaTrigger $trigger): BrokerSubscription
    {
        $spec = TriggerSpec::fromSpec($trigger);

        return new BrokerSubscription(
            new SubscriptionId($id),
            new WorkerId($workerId),
            ResourceScope::forApp(new AppId('demo')),
            SubscriptionKind::Trigger,
            Selector::exact($spec->getSharingKey()),
            $spec,
        );
    }
}
