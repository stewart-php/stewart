<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;

#[CoversNothing]
final class NoWallClockInUnitTestsTest extends TestCase
{
    private const array SCANNED = ['packages/*/tests/Unit', 'packages/*/tests/Fixtures', 'packages/runtime/tests/Integration/Protocol', 'packages/testing/src'];

    // The real adapters are the subject; SlowStarter and LingeringWatcher run inside a worker with real timers.
    private const array EXEMPT = [
        'packages/support/tests/Unit/Time/RevoltTimersTest.php',
        'packages/runtime/tests/Unit/Time/SystemClockTest.php',
        'packages/runtime/tests/Fixtures/Protocol/SlowStarter.php',
        'packages/runtime/tests/Fixtures/Protocol/LingeringWatcher.php',
    ];

    private const string SLEEP = '/(?<![\w>:$])(?<!function )(?:u?sleep|delay)\(\s*(?!0\s*[,)])|EventLoop::delay\(/';

    public function testNoUnitTestOrFixtureWaitsOnTheWallClock(): void
    {
        $repository = new RepositoryFiles();
        $offenders = [];

        foreach ($repository->listFilesIn(self::SCANNED) as $file) {
            $relative = $repository->toRelativePath($file);

            if (!\in_array($relative, self::EXEMPT, true) && preg_match(self::SLEEP, (string) file_get_contents($file)) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'Advance ManualTimers, open a Latch or use EventLoopTicks::settle() instead.');
    }
}
