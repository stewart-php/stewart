<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Text;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Support\Text\OptionNameMatch;
use Stewart\Support\Text\OptionNameMatcher;

#[CoversClass(OptionNameMatcher::class)]
#[CoversClass(OptionNameMatch::class)]
final class OptionNameMatcherTest extends TestCase
{
    private const array PARAMETERS = ['motionSensor', 'light', 'offAfterMinutes'];

    public function testAnExactNameMatches(): void
    {
        self::assertSame('light', self::createMatcher()->matchOption('light', self::PARAMETERS)->parameter);
    }

    public function testCaseDashesAndUnderscoresAreFolded(): void
    {
        foreach (['motion_sensor', 'MOTION_SENSOR', 'motion-sensor', 'motionsensor'] as $option) {
            self::assertSame('motionSensor', self::createMatcher()->matchOption($option, self::PARAMETERS)->parameter, $option);
        }
    }

    public function testAnExactNameWinsOverAFoldedOne(): void
    {
        self::assertSame('max_retries', self::createMatcher()->matchOption('max_retries', ['maxRetries', 'max_retries'])->parameter);
    }

    public function testAFoldedNameMatchingTwoParametersIsAmbiguous(): void
    {
        $match = self::createMatcher()->matchOption('MAX_RETRIES', ['maxRetries', 'max_retries']);

        self::assertTrue($match->isAmbiguous());
        self::assertNull($match->parameter);
        self::assertSame(['maxRetries', 'max_retries'], $match->candidates);
    }

    public function testAnUnknownNameSuggestsTheClosestParameter(): void
    {
        $match = self::createMatcher()->matchOption('ligth', self::PARAMETERS);

        self::assertNull($match->parameter);
        self::assertFalse($match->isAmbiguous());
        self::assertSame('light', $match->suggestion);
    }

    private static function createMatcher(): OptionNameMatcher
    {
        return new OptionNameMatcher(new ClosestNameFinder());
    }
}
