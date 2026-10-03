<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Logging;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Logging\EveryNthOccurrence;

#[CoversClass(EveryNthOccurrence::class)]
final class EveryNthOccurrenceTest extends TestCase
{
    public function testFirstOccurrenceAndEveryNthAfterAreDue(): void
    {
        $occurrences = new EveryNthOccurrence(3);

        $due = array_values(array_filter(range(0, 10), $occurrences->includesOccurrence(...)));

        self::assertSame([1, 3, 6, 9], $due);
    }
}
