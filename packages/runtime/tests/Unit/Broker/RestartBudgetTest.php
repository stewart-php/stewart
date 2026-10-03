<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Broker\RestartBudget;
use Stewart\Runtime\Model\WorkerId;

#[CoversClass(RestartBudget::class)]
final class RestartBudgetTest extends TestCase
{
    public function testWorkerMayRestartUpToItsBudget(): void
    {
        $budget = new RestartBudget(workerRestartAttempts: 3, workerRestartWindow: Duration::seconds(60));

        self::assertSame(1, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(100)));
        self::assertSame(2, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(101)));
        self::assertSame(3, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(102)));
        self::assertNull($budget->claimAttempt(new WorkerId(0), self::createInstantAt(103)), 'The fourth restart inside the window is a quarantine.');
    }

    public function testRestartsSpentInsideTheWindowCanBeCounted(): void
    {
        $budget = new RestartBudget(workerRestartAttempts: 3, workerRestartWindow: Duration::seconds(60));

        self::assertSame(0, $budget->countUsedAttempts(new WorkerId(0), self::createInstantAt(100)));

        $budget->claimAttempt(new WorkerId(0), self::createInstantAt(100));
        $budget->claimAttempt(new WorkerId(0), self::createInstantAt(130));

        self::assertSame(2, $budget->countUsedAttempts(new WorkerId(0), self::createInstantAt(150)));
        self::assertSame(1, $budget->countUsedAttempts(new WorkerId(0), self::createInstantAt(170)), 'The first restart has slid out of the window.');
        self::assertSame(0, $budget->countUsedAttempts(new WorkerId(7), self::createInstantAt(150)));
    }

    public function testClaimReturnsTheAttemptNumber(): void
    {
        $budget = new RestartBudget(workerRestartAttempts: 5, workerRestartWindow: Duration::seconds(60));

        self::assertSame(1, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(100)));
        self::assertSame(2, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(101)));
        self::assertSame(3, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(102)));
    }

    public function testWindowSlides(): void
    {
        $budget = new RestartBudget(workerRestartAttempts: 2, workerRestartWindow: Duration::seconds(60));

        self::assertSame(1, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(100)));
        self::assertSame(2, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(110)));
        self::assertNull($budget->claimAttempt(new WorkerId(0), self::createInstantAt(120)));

        self::assertSame(1, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(175)), 'A recovered worker backs off from the start again.');
    }

    public function testEachWorkerHasItsOwnBudget(): void
    {
        $budget = new RestartBudget(workerRestartAttempts: 1, workerRestartWindow: Duration::seconds(60));

        self::assertSame(1, $budget->claimAttempt(new WorkerId(0), self::createInstantAt(100)));
        self::assertNull($budget->claimAttempt(new WorkerId(0), self::createInstantAt(101)));
        self::assertSame(1, $budget->claimAttempt(new WorkerId(1), self::createInstantAt(101)), 'One crash-looping worker must not quarantine its neighbors.');
    }

    public function testZeroBudgetQuarantinesImmediately(): void
    {
        $budget = new RestartBudget(workerRestartAttempts: 0, workerRestartWindow: Duration::seconds(60));

        self::assertNull($budget->claimAttempt(new WorkerId(0), self::createInstantAt(100)));
    }

    private static function createInstantAt(int $seconds): MonotonicTime
    {
        return MonotonicTime::fromMicroseconds($seconds * 1_000_000);
    }
}
