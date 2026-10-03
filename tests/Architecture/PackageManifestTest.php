<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Tests\Architecture\Fixtures\ClassImport;
use Stewart\Tests\Architecture\Fixtures\Collection\PackageManifestCollection;
use Stewart\Tests\Architecture\Fixtures\ImportOwner;
use Stewart\Tests\Architecture\Fixtures\PackageManifest;
use Stewart\Tests\Architecture\Fixtures\PackageResolver;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;

#[CoversNothing]
final class PackageManifestTest extends TestCase
{
    private const string BRANCH_ALIAS = '0.x-dev';

    private const string SIBLING_CONSTRAINT = 'self.version';

    private const array SPLIT_REPOSITORY_FILES = ['LICENSE', 'README.md', '.github/workflows/close-pull-request.yml'];

    #[DataProvider('providePackages')]
    public function testEveryImportIsDeclaredInTheManifest(PackageManifest $package): void
    {
        $sourceFiles = $package->listSourceFiles();
        $violations = $this->collectImportViolations($sourceFiles, static fn(ImportOwner $owner): bool => $owner->isSatisfiedBy($package));

        self::assertNotSame([], $sourceFiles, \sprintf('%s has no source files; the scan is broken.', $package->name));
        self::assertSame([], $violations, \sprintf(
            "%s/composer.json does not require what its source imports:\n%s",
            new RepositoryFiles()->toRelativePath($package->directory),
            implode("\n", $violations),
        ));
    }

    #[DataProvider('providePackages')]
    public function testEveryTestImportIsDeclaredInTheManifest(PackageManifest $package): void
    {
        $testFiles = $package->listTestFiles();
        $violations = $this->collectImportViolations($testFiles, static fn(ImportOwner $owner): bool => $owner->isSatisfiedForTestsBy($package));

        self::assertNotSame([], $testFiles, \sprintf('%s has no tests.', $package->name));
        self::assertSame([], $violations, \sprintf(
            "%s/composer.json does not require or require-dev what its tests import:\n%s",
            new RepositoryFiles()->toRelativePath($package->directory),
            implode("\n", $violations),
        ));
    }

    #[DataProvider('providePackages')]
    public function testPackageCanRunItsTestsAlone(PackageManifest $package): void
    {
        $testNamespace = $package->findTestNamespace();

        self::assertNotNull($testNamespace, $package->name . ' maps no namespace to src/.');
        self::assertTrue($package->mapsTestNamespace($testNamespace), \sprintf('%s: autoload-dev must map %s to tests/.', $package->name, $testNamespace));
        self::assertTrue($package->hasFile('phpunit.xml.dist'), $package->name . ' has no phpunit.xml.dist.');
        self::assertStringContainsString('/tests export-ignore', $package->readFile('.gitattributes'), $package->name);
        self::assertSame(self::BRANCH_ALIAS, $package->mainBranchAlias, $package->name . ' extra.branch-alias.dev-main');

        foreach ($package->siblingConstraints as $sibling => $constraint) {
            self::assertSame(self::SIBLING_CONSTRAINT, $constraint, \sprintf('%s requires %s', $package->name, $sibling));
        }
    }

    #[DataProvider('providePackages')]
    public function testPackageShipsAsReadOnlySplit(PackageManifest $package): void
    {
        foreach (self::SPLIT_REPOSITORY_FILES as $file) {
            self::assertTrue($package->hasFile($file), \sprintf('%s has no %s.', $package->name, $file));
        }

        self::assertStringContainsString('/.github export-ignore', $package->readFile('.gitattributes'), $package->name);
    }

    public function testRootPinsEveryPackageVersion(): void
    {
        foreach (self::discoverPackages() as $package) {
            self::assertSame(self::BRANCH_ALIAS, self::readRootPinnedVersion($package->name), $package->name . ' is not pinned in the root path repository.');
        }
    }

    /** @return iterable<string, array{PackageManifest}> */
    public static function providePackages(): iterable
    {
        foreach (self::discoverPackages() as $package) {
            yield $package->name => [$package];
        }
    }

    /**
     * @param list<string> $files
     * @param Closure(ImportOwner): bool $isAllowed
     * @return list<string>
     */
    private function collectImportViolations(array $files, Closure $isAllowed): array
    {
        $repository = new RepositoryFiles();
        $resolver = PackageResolver::createForRepository($repository->rootPath, self::discoverPackages());
        $violations = [];

        foreach ($files as $file) {
            foreach (ClassImport::readAllFromFile($file) as $import) {
                $owner = $resolver->findOwnerOf($import->className);

                if (!$isAllowed($owner)) {
                    $violations[] = \sprintf('%s:%d  %s -> %s', $repository->toRelativePath($import->file), $import->line, $import->className, $owner->name);
                }
            }
        }

        return $violations;
    }

    private static function readRootPinnedVersion(string $package): mixed
    {
        $value = json_decode((string) file_get_contents(new RepositoryFiles()->rootPath . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        foreach (['repositories', 0, 'options', 'versions', $package] as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }

    private static function discoverPackages(): PackageManifestCollection
    {
        return PackageManifestCollection::discoverUnder(new RepositoryFiles()->rootPath . '/packages');
    }
}
