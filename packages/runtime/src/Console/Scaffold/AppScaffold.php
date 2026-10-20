<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Scaffold;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\Config\AutoloadRoot;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Config\Psr4Map;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Exception\ScaffoldError;
use Stewart\Runtime\Exception\ScaffoldException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final readonly class AppScaffold
{
    private const string STUB_DIRECTORY = __DIR__ . '/../../../resources/scaffold';

    private const string CLASS_NAME_PATTERN = '/\A[A-Z][A-Za-z0-9]*\z/';

    private const string CAMEL_CASE_WORD_BOUNDARY = '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/';

    private const string TESTS_NAMESPACE = 'Tests\\';

    private const string TESTS_DIRECTORY = 'tests';

    public function __construct(
        private Psr4Map $psr4Map,
        private ProjectRoot $projectRoot,
        private AppDiscovery $discovery,
        private Filesystem $filesystem,
        private string $appsDirectory,
    ) {}

    /** @throws AppException|IdentifierException|ScaffoldException */
    public function scaffoldApp(string $className, ?string $appId = null): ScaffoldedApp
    {
        if (preg_match(self::CLASS_NAME_PATTERN, $className) !== 1) {
            throw ScaffoldException::createForReason(ScaffoldError::ClassNameInvalid, name: $className);
        }

        $id = new AppId($appId ?? $this->deriveAppId($className));
        $appsRoot = $this->findAppsRoot();
        $testsNamespace = $appsRoot->namespacePrefix . self::TESTS_NAMESPACE;
        $appFile = Path::join($appsRoot->directory, $className . '.php');
        $testFile = Path::join($this->findTestsDirectory($testsNamespace), $className . 'Test.php');

        $this->refuseExistingFile($appFile);
        $this->refuseExistingFile($testFile);
        $this->refuseTakenId($id);

        $placeholders = [
            '{{namespace}}' => rtrim($appsRoot->namespacePrefix, '\\'),
            '{{testNamespace}}' => rtrim($testsNamespace, '\\'),
            '{{class}}' => $className,
            '{{id}}' => $id->value,
        ];
        $this->filesystem->dumpFile($appFile, $this->renderStub('App.php.stub', $placeholders));
        $this->filesystem->dumpFile($testFile, $this->renderStub('AppTest.php.stub', $placeholders));

        return new ScaffoldedApp($id, Path::makeRelative($appFile, $this->projectRoot->path), Path::makeRelative($testFile, $this->projectRoot->path));
    }

    private function deriveAppId(string $className): string
    {
        return strtolower(preg_replace(self::CAMEL_CASE_WORD_BOUNDARY, '-', $className) ?? $className);
    }

    /** @throws AppException */
    private function findAppsRoot(): AutoloadRoot
    {
        $directory = realpath($this->appsDirectory);

        return ($directory === false ? null : $this->psr4Map->findAutoloadRootContaining($directory))
            ?? throw AppException::createForReason(AppError::DirectoryNotAutoloadable, directory: $this->appsDirectory);
    }

    private function findTestsDirectory(string $testsNamespace): string
    {
        return $this->psr4Map->listDirectoriesFor($testsNamespace)[0] ?? $this->projectRoot->resolvePath(self::TESTS_DIRECTORY);
    }

    /** @throws ScaffoldException */
    private function refuseExistingFile(string $file): void
    {
        if ($this->filesystem->exists($file)) {
            throw ScaffoldException::createForReason(ScaffoldError::FileExists, path: Path::makeRelative($file, $this->projectRoot->path));
        }
    }

    /** @throws AppException|ScaffoldException */
    private function refuseTakenId(AppId $id): void
    {
        $taken = $this->discovery->discover()->apps->listAppIds()->findFirstWhere(static fn(AppId $known): bool => $known->collidesWith($id));

        if ($taken !== null) {
            throw ScaffoldException::createForReason(ScaffoldError::IdTaken, appId: $taken->value);
        }
    }

    /** @param array<string, string> $placeholders */
    private function renderStub(string $stub, array $placeholders): string
    {
        return strtr($this->filesystem->readFile(Path::join(self::STUB_DIRECTORY, $stub)), $placeholders);
    }
}
