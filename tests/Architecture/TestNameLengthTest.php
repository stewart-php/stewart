<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;

#[CoversNothing]
final class TestNameLengthTest extends TestCase
{
    private const int MAX_NAME_LENGTH = 50;

    private const string TEST_METHOD = '/function (test\w+)\(/';

    private const string LEADING_ARTICLE = '/^test(?:A|An|The)[A-Z]/';

    public function testNamesStayShort(): void
    {
        $tooLong = $this->listNamesMatching(static fn(string $name): bool => \strlen($name) > self::MAX_NAME_LENGTH);

        self::assertSame([], $tooLong, 'Name the behavior and condition in ' . self::MAX_NAME_LENGTH . ' chars or less.');
    }

    public function testNamesStartWithoutArticle(): void
    {
        $withArticle = $this->listNamesMatching(static fn(string $name): bool => preg_match(self::LEADING_ARTICLE, $name) === 1);

        self::assertSame([], $withArticle, 'Drop the leading article from the test name.');
    }

    /**
     * @param Closure(string): bool $violates
     * @return list<string>
     */
    private function listNamesMatching(Closure $violates): array
    {
        $repository = new RepositoryFiles();
        $violations = [];

        foreach ($repository->listTestFiles() as $file) {
            preg_match_all(self::TEST_METHOD, (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $name) {
                if ($violates($name)) {
                    $violations[] = $repository->toRelativePath($file) . ' ' . $name;
                }
            }
        }

        return $violations;
    }
}
