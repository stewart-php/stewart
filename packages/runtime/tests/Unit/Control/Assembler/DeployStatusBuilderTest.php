<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Assembler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Broker\Deploy\CommitId;
use Stewart\Runtime\Broker\Deploy\DeployState;
use Stewart\Runtime\Broker\Deploy\ReleaseFailure;
use Stewart\Runtime\Control\Assembler\DeployStatusBuilder;

#[CoversClass(DeployStatusBuilder::class)]
final class DeployStatusBuilderTest extends TestCase
{
    public function testNoRunningCommitMeansNoStatus(): void
    {
        self::assertNull(new DeployStatusBuilder(new DeployState())->buildDeployStatus());
    }

    public function testStatusCarriesPollAndLastFailure(): void
    {
        $state = new DeployState();
        $state->recordRunningCommit(new CommitId(str_repeat('a', 40)));
        $state->recordPoll(Instant::fromIso('2026-10-08T12:00:00Z'));
        $state->recordFailure(new ReleaseFailure(new CommitId(str_repeat('b', 40)), 'first', Instant::fromIso('2026-10-08T11:00:00Z')));
        $state->recordFailure(new ReleaseFailure(new CommitId(str_repeat('c', 40)), 'second', Instant::fromIso('2026-10-08T11:30:00Z')));

        $status = new DeployStatusBuilder($state)->buildDeployStatus();

        self::assertSame(str_repeat('a', 40), $status?->commit);
        self::assertSame('2026-10-08T12:00:00.000000Z', $status->lastPolledAt?->toIso8601());
        self::assertSame(2, $status->failures);
        self::assertNotNull($status->lastFailure);
        self::assertSame([str_repeat('c', 40), 'second'], [$status->lastFailure->commit, $status->lastFailure->reason]);
    }
}
