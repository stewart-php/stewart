<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

use Stewart\Codegen\Emitter\GeneratedCodePrinter;
use Stewart\Codegen\Exception\CodegenException;
use Stewart\Codegen\Output\Collection\FileChangeCollection;
use Stewart\Codegen\Output\Collection\GeneratedFileCollection;

final readonly class OutputDirectory
{
    private const string PHP_EXTENSION = '.php';

    public function __construct(private string $path) {}

    /** @throws CodegenException */
    public function planChanges(GeneratedFileCollection $files): FileChangeCollection
    {
        $changes = [];
        $rendered = [];

        foreach ($files as $file) {
            $rendered[$file->path] = true;
            $changes[] = new FileChange($file->path, $this->outcomeFor($file));
        }

        foreach ($this->orphanedGeneratedFiles($rendered) as $path) {
            $changes[] = new FileChange($path, FileOutcome::Deleted);
        }

        return FileChangeCollection::fromChangesSortedByPath($changes);
    }

    /** @throws CodegenException */
    public function writeChanges(GeneratedFileCollection $files, ShrinkPolicy $shrinkPolicy): FileChangeCollection
    {
        $changes = $this->planChanges($files);

        if ($shrinkPolicy === ShrinkPolicy::Refuse) {
            $this->refuseLargeShrink($changes);
        }

        $written = [];

        foreach ($changes->filterStale() as $change) {
            $written[$change->path] = true;
        }

        $this->ensureDirectory();

        foreach ($files as $file) {
            if (isset($written[$file->path])) {
                $this->writeFileAtomically($this->path . '/' . $file->path, $file->contents);
            }
        }

        foreach ($changes as $change) {
            if ($change->outcome === FileOutcome::Deleted) {
                $this->removeFile($this->path . '/' . $change->path);
            }
        }

        return $changes;
    }

    /** @throws CodegenException */
    private function outcomeFor(GeneratedFile $file): FileOutcome
    {
        $target = $this->path . '/' . $file->path;

        if (!is_file($target)) {
            return FileOutcome::Created;
        }

        $current = file_get_contents($target);

        if ($current !== false && !GeneratedCodePrinter::isGenerated($current)) {
            throw CodegenException::unmarkedFileInTheWay($target);
        }

        return $current === $file->contents ? FileOutcome::Unchanged : FileOutcome::Updated;
    }

    /** @throws CodegenException */
    private function refuseLargeShrink(FileChangeCollection $changes): void
    {
        $deleted = $changes->countWithOutcome(FileOutcome::Deleted);
        $existing = $changes->countExistingFiles();

        if ($deleted * 2 > $existing) {
            throw CodegenException::shrinkRefused($deleted, $existing);
        }
    }

    /**
     * @param array<string, true> $rendered
     * @return list<string>
     */
    private function orphanedGeneratedFiles(array $rendered): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $orphaned = [];

        foreach (scandir($this->path) ?: [] as $name) {
            $file = $this->path . '/' . $name;

            if (!str_ends_with($name, self::PHP_EXTENSION) || isset($rendered[$name]) || !is_file($file)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents !== false && GeneratedCodePrinter::isGenerated($contents)) {
                $orphaned[] = $name;
            }
        }

        return $orphaned;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->path)) {
            return;
        }

        if (!mkdir($this->path, 0o777, true) && !is_dir($this->path)) {
            throw CodegenException::outputDirectoryNotCreatable($this->path);
        }
    }

    private function writeFileAtomically(string $target, string $contents): void
    {
        // Not `.php`, so the stale scan and the autoloader ignore leftovers of an interrupted run.
        $temporary = \sprintf('%s.%s.tmp', $target, bin2hex(random_bytes(4)));

        if (file_put_contents($temporary, $contents) === false || !rename($temporary, $target)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw CodegenException::fileNotWritable($target);
        }
    }

    private function removeFile(string $target): void
    {
        if (is_file($target) && !unlink($target)) {
            throw CodegenException::fileNotDeletable($target);
        }
    }
}
