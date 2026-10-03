<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PhpToken;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;

#[CoversNothing]
final class CommentBarTest extends TestCase
{
    private const array SCANNED_DIRECTORIES = ['packages', 'tests', 'skeleton'];

    private const array SCANNED_FILES = ['packages/runtime/bin/stewart'];

    private const array SKIPPED_PATHS = ['/vendor/', 'packages/codegen/tests/Fixtures/Expected/', 'packages/runtime/tests/Fixtures/Generated/Code/'];

    private const int MAX_COMMENT_LENGTH = 120;

    public function testDocblocksHoldOnlyTags(): void
    {
        $violations = [];

        foreach ($this->tokenizeScannedFiles() as $relative => $tokens) {
            foreach ($tokens as $token) {
                if ($token->is(\T_DOC_COMMENT) && !str_contains($token->text, '@')) {
                    $violations[] = \sprintf('%s:%d', $relative, $token->line);
                }
            }
        }

        self::assertSame([], $violations, 'Docblocks hold type tags only; write a "why" as a one-line // comment.');
    }

    public function testInlineCommentsAreSingleShortLines(): void
    {
        $violations = [];

        foreach ($this->tokenizeScannedFiles() as $relative => $tokens) {
            $previousCommentLine = null;

            foreach ($tokens as $token) {
                if (!$token->is(\T_COMMENT)) {
                    continue;
                }

                $text = trim($token->text);
                $location = \sprintf('%s:%d', $relative, $token->line);

                if (str_starts_with($text, '/*')) {
                    $violations[] = $location . ' block comment';
                } elseif ($previousCommentLine === $token->line - 1) {
                    $violations[] = $location . ' multi-line comment';
                } elseif (\strlen($text) > self::MAX_COMMENT_LENGTH) {
                    $violations[] = $location . ' longer than ' . self::MAX_COMMENT_LENGTH . ' chars';
                }

                $previousCommentLine = $token->line;
            }
        }

        self::assertSame([], $violations);
    }

    /** @return iterable<string, array<PhpToken>> */
    private function tokenizeScannedFiles(): iterable
    {
        $repository = new RepositoryFiles();

        foreach ($repository->listFilesIn(self::SCANNED_DIRECTORIES) as $file) {
            $relative = $repository->toRelativePath($file);

            if (!$this->isSkippedPath($relative)) {
                yield $relative => PhpToken::tokenize((string) file_get_contents($file));
            }
        }

        foreach (self::SCANNED_FILES as $relative) {
            yield $relative => PhpToken::tokenize((string) file_get_contents($repository->rootPath . '/' . $relative));
        }
    }

    private function isSkippedPath(string $relative): bool
    {
        foreach (self::SKIPPED_PATHS as $skipped) {
            if (str_contains('/' . $relative, $skipped)) {
                return true;
            }
        }

        return false;
    }
}
