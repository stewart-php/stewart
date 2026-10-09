<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;

#[CoversClass(ExposedStateChange::class)]
final class ExposedStateChangeTest extends TestCase
{
    public function testLaterFieldsWinAndUnsetFieldsStay(): void
    {
        $merged = new ExposedStateChange(new ExposedState(21.4), ['source' => 'hall'], true)
            ->withLaterChange(new ExposedStateChange(new ExposedState(null), available: false));

        self::assertNull($merged->state?->value);
        self::assertNotNull($merged->state);
        self::assertSame(['source' => 'hall'], $merged->attributes);
        self::assertFalse($merged->available);
    }
}
