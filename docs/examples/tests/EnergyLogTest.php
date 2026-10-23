<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\EnergyLog;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\App\AppHarness;
use Stewart\Testing\Filesystem\TempDirectory;

final class EnergyLogTest extends TestCase
{
    private AppHarness $harness;

    private TempDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = TempDirectory::createWithPrefix('energy-log-');
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('sensor.house_power', '400');
        $this->harness->startApp(EnergyLog::class, ['path' => $this->directory->getFilePath('power.log')]);
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testReadingsAreWrittenUntilTheAppStops(): void
    {
        $this->harness->ha->pushState('sensor.house_power', '1250');
        $this->harness->ha->pushState('sensor.house_power', '980');
        $this->harness->stopApp();

        self::assertSame("1250\n980\n", file_get_contents($this->directory->getFilePath('power.log')));
    }
}
