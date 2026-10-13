<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\WaitError;
use Stewart\Contracts\Exception\WaitException;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\HandlerFailureSampler;
use Throwable;

#[CoversClass(HandlerFailureSampler::class)]
final class HandlerFailureSamplerTest extends TestCase
{
    public function testFirstAndEveryHundredthFailureAreSampled(): void
    {
        $sampler = new HandlerFailureSampler();
        $sampled = [];

        for ($failure = 0; $failure < 250; ++$failure) {
            $occurrence = $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, self::createLightsFailure());

            if ($sampler->isSampled($occurrence)) {
                $sampled[] = $occurrence;
            }
        }

        self::assertSame([1, 100, 200], $sampled);
    }

    public function testFailuresFromOneThrowSiteShareASeries(): void
    {
        $sampler = new HandlerFailureSampler();
        $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, self::createLightsFailure());

        self::assertSame(2, $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, self::createLightsFailure()));
    }

    public function testEachThrowSiteAndScopeIsCountedOnItsOwn(): void
    {
        $sampler = new HandlerFailureSampler();
        $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, self::createLightsFailure());

        self::assertSame(1, $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, self::createHeatingFailure()));
        self::assertSame(1, $sampler->recordFailure(self::createScope('echo'), AppFailurePhase::Handler, self::createLightsFailure()));
    }

    public function testFactoryBuiltFailuresAreKeyedByTheLineThatAskedForThem(): void
    {
        $sampler = new HandlerFailureSampler();
        $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, WaitException::createForReason(WaitError::Abandoned));

        self::assertSame(1, $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, WaitException::createForReason(WaitError::Abandoned)));
    }

    public function testFailuresInsideOneRuntimeCallAreKeyedByTheAppLine(): void
    {
        $app = new class {
            public function failInMorning(): Throwable
            {
                return WaitException::createForReason(WaitError::Abandoned);
            }

            public function failInEvening(): Throwable
            {
                return WaitException::createForReason(WaitError::Abandoned);
            }
        };
        $sampler = new HandlerFailureSampler();
        $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, $app->failInMorning());

        self::assertSame(1, $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, $app->failInEvening()));
        self::assertSame(2, $sampler->recordFailure(self::createScope('demo'), AppFailurePhase::Handler, $app->failInMorning()));
    }

    public function testLifecycleFailuresAreNeverThrottled(): void
    {
        $sampler = new HandlerFailureSampler();

        foreach ([AppFailurePhase::Construct, AppFailurePhase::Initialize, AppFailurePhase::Dispose, AppFailurePhase::Schedule] as $phase) {
            $sampler->recordFailure(self::createScope('demo'), $phase, self::createLightsFailure());

            self::assertSame(1, $sampler->recordFailure(self::createScope('demo'), $phase, self::createLightsFailure()), $phase->value);
        }
    }

    private static function createLightsFailure(): RuntimeException
    {
        return new RuntimeException('lights failed');
    }

    private static function createHeatingFailure(): RuntimeException
    {
        return new RuntimeException('heating failed');
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }
}
