<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\HandlerFailureSampler;

#[CoversClass(HandlerFailureSampler::class)]
final class HandlerFailureSamplerTest extends TestCase
{
    public function testFirstAndEveryHundredthFailureAreSampled(): void
    {
        $sampler = new HandlerFailureSampler();
        $sampled = [];

        for ($failure = 0; $failure < 250; ++$failure) {
            $occurrence = $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, 'subscription w0:1');

            if ($sampler->isSampled($occurrence)) {
                $sampled[] = $occurrence;
            }
        }

        self::assertSame([1, 100, 200], $sampled);
    }

    public function testEachOriginIsCountedOnItsOwn(): void
    {
        $sampler = new HandlerFailureSampler();
        $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, 'subscription w0:1');

        self::assertSame(1, $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, 'subscription w0:2'));
        self::assertSame(1, $sampler->recordFailure(self::createScope('echo'), AppFailurePhase::Handler, 'subscription w0:1'));
        self::assertSame(2, $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, 'subscription w0:1'));
    }

    public function testLifecycleFailuresAreNeverThrottled(): void
    {
        $sampler = new HandlerFailureSampler();

        foreach ([AppFailurePhase::Construct, AppFailurePhase::Initialize, AppFailurePhase::Dispose, AppFailurePhase::Schedule] as $phase) {
            $sampler->recordFailure(self::createScope('demo'), $phase, null);

            self::assertSame(1, $sampler->recordFailure(self::createScope('demo'), $phase, null), $phase->value);
        }
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }
}
