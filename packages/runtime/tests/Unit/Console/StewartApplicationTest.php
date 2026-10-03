<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Console\StewartApplication;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(StewartApplication::class)]
final class StewartApplicationTest extends TestCase
{
    private const string MISSING_CONFIG = '/nonexistent/stewart.yaml';

    private const string VERBOSITY_VARIABLE = 'SHELL_VERBOSITY';

    private string|false $originalVerbosity;

    protected function setUp(): void
    {
        $this->originalVerbosity = getenv(self::VERBOSITY_VARIABLE);
    }

    protected function tearDown(): void
    {
        // Application::run() exports the verbosity process-wide.
        putenv($this->originalVerbosity === false ? self::VERBOSITY_VARIABLE : self::VERBOSITY_VARIABLE . '=' . $this->originalVerbosity);
        unset($_ENV[self::VERBOSITY_VARIABLE], $_SERVER[self::VERBOSITY_VARIABLE]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function provideLeadingRunOptions(): iterable
    {
        yield 'short config' => [['-c', self::MISSING_CONFIG]];
        yield 'long config' => [['--config', self::MISSING_CONFIG]];
        yield 'log level first' => [['--log-level', 'debug', '-c', self::MISSING_CONFIG]];
        yield 'short log level' => [['-l', 'debug', '-c', self::MISSING_CONFIG]];
        yield 'inline config' => [['--config=' . self::MISSING_CONFIG]];
    }

    /** @param list<string> $arguments */
    #[DataProvider('provideLeadingRunOptions')]
    public function testLeadingRunOptionsReachRun(array $arguments): void
    {
        $output = $this->runApplication($arguments);

        self::assertStringContainsString('Config file ' . self::MISSING_CONFIG . ' not found.', $output);
        self::assertStringNotContainsString('is not defined', $output);
        self::assertStringNotContainsString('pass --address', $output, 'status would add this hint; run must have handled it.');
    }

    public function testCommandAfterLeadingOptionIsUsed(): void
    {
        $output = $this->runApplication(['-c', self::MISSING_CONFIG, 'status']);

        self::assertStringContainsString('pass --address and --token', $output);
        self::assertStringContainsString(self::MISSING_CONFIG, $output);
    }

    public function testMistypedCommandStillGetsSuggestion(): void
    {
        $output = $this->runApplication(['-n', 'stauts']);

        self::assertStringContainsString('Command "stauts" is not defined.', $output);
        self::assertStringContainsString('status', $output);
    }

    /** @param list<string> $arguments */
    private function runApplication(array $arguments): string
    {
        $application = new ConsoleKernel(sys_get_temp_dir(), [], new SyntheticServices()->withService(ConfigLoader::class, ConfigLoaderFixture::createLoader()))->createApplication();
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exit = $application->run(new ArgvInput(['stewart', ...$arguments]), $output);

        self::assertSame(Command::FAILURE, $exit);

        return $output->fetch();
    }
}
