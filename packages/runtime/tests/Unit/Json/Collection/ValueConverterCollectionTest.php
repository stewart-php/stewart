<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Json\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\DurationConverter;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\IsoInstantConverter;

#[CoversClass(ValueConverterCollection::class)]
final class ValueConverterCollectionTest extends TestCase
{
    public function testFindsTheConverterForItsHandledClass(): void
    {
        $duration = new DurationConverter();
        $converters = ValueConverterCollection::keyedByHandledClass([new EpochInstantConverter(), $duration]);

        self::assertSame($duration, $converters->findForClass(Duration::class));
        self::assertNull($converters->findForClass(self::class));
    }

    public function testLaterConverterReplacesEarlierForSameClass(): void
    {
        $iso = new IsoInstantConverter();

        self::assertSame($iso, ValueConverterCollection::keyedByHandledClass([new EpochInstantConverter(), $iso])->findForClass(Instant::class));
    }
}
