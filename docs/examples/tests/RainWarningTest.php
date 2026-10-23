<?php

declare(strict_types=1);

namespace App\Tests;

use App\RainWarning;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class RainWarningTest extends TestCase
{
    public function testRainyDaysSendAWarning(): void
    {
        $harness = new AppHarness();
        $harness->ha->stubActionAnswer('weather.get_forecasts', [
            'weather.home' => ['forecast' => [['condition' => 'rainy', 'precipitation_probability' => 80]]],
        ]);
        $harness->startApp(RainWarning::class);

        $harness->advanceTime(Duration::hours(7));

        self::assertSame('Take an umbrella: 80% chance of rain.', $harness->ha->getLastCall()->data['message'] ?? null);
    }

    public function testAMissingForecastIsLogged(): void
    {
        $harness = new AppHarness();
        $harness->ha->stubActionFailure('weather.get_forecasts', ActionCallException::unreachable('weather', 'get_forecasts', 'disconnected'));
        $harness->startApp(RainWarning::class);

        $harness->advanceTime(Duration::hours(7));

        self::assertCount(1, $harness->logger->listMessagesAt('warning'));
    }
}
