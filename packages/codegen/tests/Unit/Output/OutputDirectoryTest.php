<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Emitter\GeneratedCodePrinter;
use Stewart\Codegen\Exception\CodegenError;
use Stewart\Codegen\Output\Collection\FileChangeCollection;
use Stewart\Codegen\Output\Collection\GeneratedFileCollection;
use Stewart\Codegen\Output\FileChange;
use Stewart\Codegen\Output\FileOutcome;
use Stewart\Codegen\Output\GeneratedFile;
use Stewart\Codegen\Output\OutputDirectory;
use Stewart\Codegen\Output\ShrinkPolicy;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(OutputDirectory::class)]
#[CoversClass(FileChange::class)]
#[CoversClass(FileChangeCollection::class)]
#[CoversClass(FileOutcome::class)]
#[CoversClass(GeneratedFile::class)]
#[CoversClass(GeneratedFileCollection::class)]
final class OutputDirectoryTest extends TestCase
{
    use AssertsReason;

    private TempDirectory $temp;

    private string $path;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-output-');
        $this->path = $this->temp->getFilePath('generated');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testFirstWriteCreatesDirectory(): void
    {
        $changes = self::writeFiles(new OutputDirectory($this->path), self::createGeneratedFile('Entities.php', 'one'));

        self::assertSame([FileOutcome::Created], self::listOutcomes($changes));
        self::assertStringContainsString('one', (string) file_get_contents($this->path . '/Entities.php'));
    }

    public function testWriteLeavesNoTemporaryFile(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('Entities.php', 'one'));
        self::writeFiles($output, self::createGeneratedFile('Entities.php', 'two'));

        self::assertSame([$this->path . '/Entities.php'], glob($this->path . '/*'));
        self::assertStringContainsString('two', (string) file_get_contents($this->path . '/Entities.php'));
    }

    public function testIdenticalRunChangesNothing(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('Entities.php', 'one'));

        self::assertSame([FileOutcome::Unchanged], self::listOutcomes(self::writeFiles($output, self::createGeneratedFile('Entities.php', 'one'))));
        self::assertSame([FileOutcome::Updated], self::listOutcomes(self::writeFiles($output, self::createGeneratedFile('Entities.php', 'two'))));
    }

    public function testDeletesOnlyStaleGeneratedFiles(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('LightEntity.php', 'light'), self::createGeneratedFile('OldEntity.php', 'old'));

        file_put_contents($this->path . '/Handwritten.php', "<?php\n// mine\n");

        $changes = self::writeFiles($output, self::createGeneratedFile('LightEntity.php', 'light'));

        self::assertEquals(
            [new FileChange('LightEntity.php', FileOutcome::Unchanged), new FileChange('OldEntity.php', FileOutcome::Deleted)],
            $changes->listValues(),
        );
        self::assertFileDoesNotExist($this->path . '/OldEntity.php');
        self::assertFileExists($this->path . '/Handwritten.php');
    }

    public function testDeletesOrphansUnderBracketedPath(): void
    {
        $output = new OutputDirectory($this->temp->getFilePath('out[1]'));
        self::writeFiles($output, self::createGeneratedFile('LightEntity.php', 'light'), self::createGeneratedFile('OldEntity.php', 'old'));

        $changes = self::writeFiles($output, self::createGeneratedFile('LightEntity.php', 'light'));

        self::assertSame([FileOutcome::Unchanged, FileOutcome::Deleted], self::listOutcomes($changes));
        self::assertFileDoesNotExist($this->temp->getFilePath('out[1]/OldEntity.php'));
    }

    public function testPlanningTouchesNothing(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('Entities.php', 'one'), self::createGeneratedFile('Gone.php', 'gone'));

        $plan = $output->planChanges(GeneratedFileCollection::fromFilesSortedByPath([self::createGeneratedFile('Entities.php', 'two'), self::createGeneratedFile('New.php', 'new')]));

        self::assertEquals([
            new FileChange('Entities.php', FileOutcome::Updated),
            new FileChange('Gone.php', FileOutcome::Deleted),
            new FileChange('New.php', FileOutcome::Created),
        ], $plan->listValues());
        self::assertFileExists($this->path . '/Gone.php');
        self::assertStringContainsString('one', (string) file_get_contents($this->path . '/Entities.php'));
    }

    public function testUnmarkedFileInTheWayIsRefused(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('Entities.php', 'one'));
        file_put_contents($this->path . '/LightEntity.php', "<?php\n// mine\n");
        $files = GeneratedFileCollection::fromFilesSortedByPath([self::createGeneratedFile('Entities.php', 'two'), self::createGeneratedFile('LightEntity.php', 'light')]);

        $this->assertThrowsReason(CodegenError::UnmarkedFileInTheWay, static fn() => $output->planChanges($files));
        $this->assertThrowsReason(CodegenError::UnmarkedFileInTheWay, static fn() => $output->writeChanges($files, ShrinkPolicy::Allow));

        self::assertSame("<?php\n// mine\n", file_get_contents($this->path . '/LightEntity.php'));
        self::assertStringContainsString('one', (string) file_get_contents($this->path . '/Entities.php'));
    }

    public function testWriteDeletingMostFilesIsRefused(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('A.php', 'a'), self::createGeneratedFile('B.php', 'b'), self::createGeneratedFile('C.php', 'c'));

        $this->assertThrowsReason(CodegenError::ShrinkRefused, static fn() => self::writeFiles($output, self::createGeneratedFile('A.php', 'changed')));

        self::assertFileExists($this->path . '/B.php');
        self::assertFileExists($this->path . '/C.php');
        self::assertStringContainsString('// a', (string) file_get_contents($this->path . '/A.php'));
    }

    public function testAllowedShrinkDeletesOrphans(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('A.php', 'a'), self::createGeneratedFile('B.php', 'b'), self::createGeneratedFile('C.php', 'c'));

        $changes = $output->writeChanges(GeneratedFileCollection::fromFilesSortedByPath([self::createGeneratedFile('A.php', 'a')]), ShrinkPolicy::Allow);

        self::assertSame(2, $changes->countWithOutcome(FileOutcome::Deleted));
        self::assertSame([$this->path . '/A.php'], glob($this->path . '/*'));
    }

    public function testDeletingHalfTheFilesIsAllowed(): void
    {
        $output = new OutputDirectory($this->path);
        self::writeFiles($output, self::createGeneratedFile('A.php', 'a'), self::createGeneratedFile('B.php', 'b'));

        $changes = self::writeFiles($output, self::createGeneratedFile('A.php', 'a'), self::createGeneratedFile('New.php', 'new'));

        self::assertSame([FileOutcome::Unchanged, FileOutcome::Deleted, FileOutcome::Created], self::listOutcomes($changes));
    }

    private static function writeFiles(OutputDirectory $output, GeneratedFile ...$files): FileChangeCollection
    {
        return $output->writeChanges(GeneratedFileCollection::fromFilesSortedByPath($files), ShrinkPolicy::Refuse);
    }

    private static function createGeneratedFile(string $name, string $body): GeneratedFile
    {
        return new GeneratedFile($name, "<?php\n\n" . GeneratedCodePrinter::buildHeaderComment() . "\n\n// " . $body . "\n");
    }

    /** @return list<FileOutcome> */
    private static function listOutcomes(FileChangeCollection $changes): array
    {
        return array_map(static fn(FileChange $change): FileOutcome => $change->outcome, $changes->listValues());
    }
}
