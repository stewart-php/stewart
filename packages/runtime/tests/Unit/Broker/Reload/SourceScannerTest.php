<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Reload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\Reload\SourceFingerprint;
use Stewart\Runtime\Broker\Reload\SourceScanner;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(SourceScanner::class)]
#[CoversClass(SourceFingerprint::class)]
final class SourceScannerTest extends TestCase
{
    private TempDirectory $project;

    private SourceScanner $scanner;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-sources-');
        mkdir($this->project->getFilePath('apps'));
        file_put_contents($this->project->getFilePath('apps/Porch.php'), "<?php\n");
        $this->scanner = new SourceScanner();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testUnchangedFilesKeepTheirFingerprint(): void
    {
        self::assertTrue($this->scan()->matches($this->scan()));
    }

    public function testSavedFileChangesTheFingerprint(): void
    {
        $before = $this->scan();
        touch($this->project->getFilePath('apps/Porch.php'), (int) filemtime($this->project->getFilePath('apps/Porch.php')) + 10);

        self::assertFalse($before->matches($this->scan()));
    }

    public function testAddedAppChangesTheFingerprint(): void
    {
        $before = $this->scan();
        file_put_contents($this->project->getFilePath('apps/Hall.php'), "<?php\n");

        self::assertFalse($before->matches($this->scan()));
    }

    public function testOtherFilesInAWatchedDirectoryAreIgnored(): void
    {
        $before = $this->scan();
        file_put_contents($this->project->getFilePath('apps/Porch.php.swp'), 'editor state');

        self::assertTrue($before->matches($this->scan()));
    }

    public function testFileThatAppearsChangesTheFingerprint(): void
    {
        $services = $this->project->getFilePath('services.php');
        $before = $this->scanner->scanFingerprint([$services]);

        self::assertTrue($before->matches($this->scanner->scanFingerprint([$services])), 'A missing file is stable.');

        file_put_contents($services, "<?php\n");

        self::assertFalse($before->matches($this->scanner->scanFingerprint([$services])));
    }

    private function scan(): SourceFingerprint
    {
        return $this->scanner->scanFingerprint([$this->project->getFilePath('apps')]);
    }
}
