<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Wire;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Support\Wire\ActionFields;

#[CoversClass(ActionFields::class)]
final class ActionFieldsTest extends TestCase
{
    public function testDropsOnlyTheFieldsNobodySet(): void
    {
        self::assertSame(
            ['brightness' => 0, 'transition' => 1.5, 'flash' => false, 'effect' => '', 'rgb_color' => []],
            ActionFields::fromFieldsDroppingNulls([
                'brightness' => 0,
                'transition' => 1.5,
                'flash' => false,
                'effect' => '',
                'rgb_color' => [],
                'color_temp' => null,
            ])->fields,
        );
    }

    public function testNothingSetIsAnEmptyPayload(): void
    {
        self::assertSame([], ActionFields::fromFieldsDroppingNulls(['a' => null, 'b' => null])->fields);
    }
}
