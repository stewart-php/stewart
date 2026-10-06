<?php

declare(strict_types=1);

namespace Stewart\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ApplicationTest extends TestCase
{
    private const string BINARY = __DIR__ . '/../../../vendor/bin/stewart';

    public function testEveryInstalledCommandIsListed(): void
    {
        $listing = json_decode(self::runBinary(['list', '--format=json'], 0), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($listing);
        self::assertIsArray($listing['commands'] ?? null);

        $names = array_column($listing['commands'], 'name');

        foreach (['run', 'config:dump', 'config:reference', 'status', 'app:pause', 'app:resume', 'app:reset', 'doctor', 'generate'] as $name) {
            self::assertContains($name, $names);
        }
    }

    public function testLeadingConfigOptionReachesRun(): void
    {
        $stdout = self::runBinary(['-c', '/nonexistent/stewart.yaml'], 1);

        self::assertStringContainsString('Config file /nonexistent/stewart.yaml not found.', $stdout);
    }

    /** @param list<string> $arguments */
    private static function runBinary(array $arguments, int $expectedExitCode): string
    {
        $process = proc_open([\PHP_BINARY, self::BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame($expectedExitCode, proc_close($process), $stderr);

        return $stdout;
    }
}
