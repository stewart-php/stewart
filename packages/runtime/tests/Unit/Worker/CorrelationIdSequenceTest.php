<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Worker\CorrelationIdSequence;

#[CoversClass(CorrelationIdSequence::class)]
final class CorrelationIdSequenceTest extends TestCase
{
    public function testIdsArePrefixedByWorkerAndNeverRepeat(): void
    {
        $sequence = new CorrelationIdSequence(new WorkerId(3));

        self::assertSame('3:0', $sequence->issueNext()->value);
        self::assertSame('3:1', $sequence->issueNext()->value);
    }
}
