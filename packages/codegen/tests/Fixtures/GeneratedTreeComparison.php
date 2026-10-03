<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Fixtures;

use PHPUnit\Framework\Assert;
use Stewart\Codegen\Output\FileChange;
use Stewart\Codegen\Output\FileOutcome;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Contracts\Generated\GeneratedFormat;

final readonly class GeneratedTreeComparison
{
    private const string UPDATE_VARIABLE = 'UPDATE_GOLDEN';

    public function __construct(private GenerationRun $generation) {}

    public function assertCommittedTreeMatches(Snapshot $snapshot): void
    {
        if (getenv(self::UPDATE_VARIABLE) === '1') {
            $this->refuseRewriteWithoutVersionBump($snapshot);
            $this->generation->writeFiles($snapshot);
        }

        $stale = $this->generation->checkAgainstSnapshot($snapshot)->listStaleFiles();

        Assert::assertSame(
            [],
            $stale->mapToList(static fn(FileChange $change): string => $change->path),
            'The committed tree is out of date; rerun with ' . self::UPDATE_VARIABLE . '=1.',
        );
    }

    private function refuseRewriteWithoutVersionBump(Snapshot $snapshot): void
    {
        $rewritten = $this->generation->checkAgainstSnapshot($snapshot)->listStaleFiles()
            ->filter(static fn(FileChange $change): bool => $change->outcome !== FileOutcome::Created);

        if ($rewritten->isEmpty() || $this->readCommittedFormatVersion() !== GeneratedFormat::VERSION) {
            return;
        }

        Assert::fail(\sprintf(
            '%s changed while GeneratedFormat::VERSION is still %d; bump it before rewriting the tree.',
            implode(', ', $rewritten->mapToList(static fn(FileChange $change): string => $change->path)),
            GeneratedFormat::VERSION,
        ));
    }

    private function readCommittedFormatVersion(): int
    {
        $path = $this->generation->getManifestPath();
        $manifest = is_file($path) ? (string) file_get_contents($path) : '';
        $pattern = \sprintf('/const int %s = (\d+);/', GeneratedFormat::MANIFEST_CONSTANT);

        return preg_match($pattern, $manifest, $match) === 1 ? (int) $match[1] : 0;
    }
}
