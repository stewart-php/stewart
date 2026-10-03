<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Console\GenerateCommand;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Snapshot\SnapshotFetcher;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshotFetcher;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(GenerateCommand::class)]
final class GenerateCommandTest extends TestCase
{
    private const string NAMESPACE = 'Acme\\Home';

    private const string OUTPUT_DIR = 'generated';

    private TempDirectory $temp;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-generate-');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testSnapshotOnDiskIsEnoughToWriteTheWholeTree(): void
    {
        $tester = $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('entities: 10 in 5 domains', $tester->getDisplay());
        self::assertStringContainsString('ignored: 3', $tester->getDisplay());
        self::assertFileExists($this->getOutputDirectory() . '/Entities.php');
        self::assertFileExists($this->getOutputDirectory() . '/LightEntity.php');
        self::assertStringContainsString('namespace Acme\\Home;', (string) file_get_contents($this->getOutputDirectory() . '/Entities.php'));
    }

    public function testWithoutSnapshotFileFetchesFromHomeAssistant(): void
    {
        $fetcher = new GoldenSnapshotFetcher();

        $tester = $this->runGenerateCommand([], fetcher: $fetcher);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('ws://ha.local:8123/api/websocket', $fetcher->fetchedFromUrl);
        self::assertFileExists($this->getOutputDirectory() . '/LightEntity.php');
    }

    public function testSummaryCountsTypedAttributes(): void
    {
        $tester = $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath()]);

        self::assertStringContainsString('attributes: 23 typed ·', $tester->getDisplay());
        self::assertStringNotContainsString('calibrated_at', $tester->getDisplay());
    }

    public function testSummaryNamesHomeAssistantVersion(): void
    {
        $tester = $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath()]);

        self::assertStringContainsString('· from Home Assistant 2026.9.1', $tester->getDisplay());
    }

    public function testWarningsReachTheConsole(): void
    {
        $display = $this->runGenerateCommand(
            ['--snapshot-in' => GoldenSnapshot::getSnapshotPath()],
            codegen: "  include: ['*', 'cover.*']\n",
        )->getDisplay();

        self::assertStringContainsString('The codegen include cover.* matches no entity.', $display);
    }

    public function testShrinkingWriteIsRefusedUntilAllowed(): void
    {
        $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath()]);
        $pumpOnly = $this->writePumpOnlySnapshot();

        $refused = $this->runGenerateCommand(['--snapshot-in' => $pumpOnly]);

        self::assertSame(Command::FAILURE, $refused->getStatusCode());
        self::assertStringContainsString('ARGS=--allow-shrink', $refused->getDisplay());
        self::assertFileExists($this->getOutputDirectory() . '/LightEntity.php');

        $allowed = $this->runGenerateCommand(['--snapshot-in' => $pumpOnly, '--allow-shrink' => true]);

        self::assertSame(Command::SUCCESS, $allowed->getStatusCode());
        self::assertFileDoesNotExist($this->getOutputDirectory() . '/LightEntity.php');
    }

    public function testCheckIsNotStoppedByShrinkGuard(): void
    {
        $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath()]);

        $tester = $this->runGenerateCommand(['--snapshot-in' => $this->writePumpOnlySnapshot(), '--check' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('LightEntity.php', $tester->getDisplay());
        self::assertStringNotContainsString('allow-shrink', $tester->getDisplay());
    }

    public function testExactIncludeNoEntityReportsIsWarnedAbout(): void
    {
        $display = $this->runGenerateCommand(
            ['--snapshot-in' => GoldenSnapshot::getSnapshotPath()],
            codegen: "  attributes:\n    sensor:\n      include: [mop_pad, mop_*]\n",
        )->getDisplay();

        self::assertStringContainsString('No sensor entity reports the included attribute mop_pad.', $display);
        self::assertStringNotContainsString('mop_*', $display);
    }

    public function testAttributeRulesReachGeneratedClasses(): void
    {
        $this->runGenerateCommand(
            ['--snapshot-in' => GoldenSnapshot::getSnapshotPath()],
            codegen: "  attributes:\n    sensor:\n      include: [calibrated_at]\n      exclude: [battery]\n",
        );

        $state = (string) file_get_contents($this->getOutputDirectory() . '/SensorState.php');

        self::assertStringContainsString('function getCalibratedAt()', $state);
        self::assertStringNotContainsString('function battery()', $state);
    }

    public function testCheckPassesWhenWrittenAndFailsWhenStale(): void
    {
        $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath()]);

        $clean = $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath(), '--check' => true]);

        self::assertSame(Command::SUCCESS, $clean->getStatusCode());
        self::assertStringContainsString('match Home Assistant', $clean->getDisplay());

        unlink($this->getOutputDirectory() . '/LightEntity.php');
        $stale = $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath(), '--check' => true]);

        self::assertSame(Command::FAILURE, $stale->getStatusCode());
        self::assertStringContainsString('LightEntity.php', $stale->getDisplay());
        self::assertStringContainsString('make generate', $stale->getDisplay());
    }

    public function testCheckReportsUnwrittenTreeOnFirstRun(): void
    {
        $tester = $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath(), '--check' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('created', $tester->getDisplay());
    }

    public function testDumpedSnapshotIsReadableAgain(): void
    {
        $dump = $this->temp->getFilePath('snapshot.json');

        self::assertSame(Command::SUCCESS, $this->runGenerateCommand([
            '--snapshot-in' => GoldenSnapshot::getSnapshotPath(),
            '--snapshot-out' => $dump,
        ])->getStatusCode());

        self::assertEquals(
            GoldenSnapshot::loadSnapshot(),
            new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot((string) file_get_contents($dump), $dump),
        );
    }

    public function testAutoloadMismatchIsRefusedWithSnippet(): void
    {
        $tester = $this->runGenerateCommand(['--snapshot-in' => GoldenSnapshot::getSnapshotPath()], psr4: ['Acme\\Home\\' => ['/elsewhere']]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"Acme\\\\Home\\\\": "generated/"', $tester->getDisplay());
        self::assertStringContainsString('make install', $tester->getDisplay());
        self::assertFileDoesNotExist($this->getOutputDirectory() . '/Entities.php');
    }

    public function testMissingSnapshotIsReportedNotThrown(): void
    {
        $tester = $this->runGenerateCommand(['--snapshot-in' => $this->temp->getFilePath('missing.json')]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('cannot be read', $tester->getDisplay());
    }

    private function writePumpOnlySnapshot(): string
    {
        $path = $this->temp->getFilePath('pump-only.json');
        file_put_contents($path, json_encode(['states' => [['entity_id' => 'switch.pump', 'state' => 'on', 'attributes' => []]]], \JSON_THROW_ON_ERROR));

        return $path;
    }

    private function getOutputDirectory(): string
    {
        return $this->temp->getFilePath(self::OUTPUT_DIR);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, array<int, string>>|null $psr4
     */
    private function runGenerateCommand(array $options, ?array $psr4 = null, string $codegen = '', ?SnapshotFetcher $fetcher = null): CommandTester
    {
        $config = $this->temp->getFilePath('stewart.yaml');

        file_put_contents($config, \sprintf(
            "home_assistant:\n  url: ws://ha.local:8123/api/websocket\n  token: from-the-file\ncodegen:\n  namespace: '%s'\n  output_dir: %s\n  exclude:\n    - light.debug_*\n%s",
            self::NAMESPACE,
            self::OUTPUT_DIR,
            $codegen,
        ));

        $psr4 ??= [self::NAMESPACE . '\\' => [$this->temp->getFilePath('vendor/composer/../../' . self::OUTPUT_DIR)]];
        $tester = new CommandTester(new ConsoleKernel($this->temp->path, $psr4, $this->createServicesWithoutEnvironment($fetcher))->createApplication()->find('generate'));

        $tester->execute(['--config' => $config, ...$options]);

        return $tester;
    }

    private function createServicesWithoutEnvironment(?SnapshotFetcher $fetcher): SyntheticServices
    {
        $services = new SyntheticServices()->withService(EnvironmentVariables::class, new EnvironmentVariables());

        return $fetcher === null ? $services : $services->withService(SnapshotFetcher::class, $fetcher);
    }
}
