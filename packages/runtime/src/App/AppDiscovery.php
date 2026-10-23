<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use ReflectionClass;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Automation;
use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\Config\AutoloadRoot;
use Stewart\Runtime\Config\Psr4Map;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Exception\AppException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

final readonly class AppDiscovery
{
    public function __construct(
        private Psr4Map $psr4Map,
        private string $appsDirectory,
    ) {}

    /** @throws AppException */
    public function discover(): DiscoveryResult
    {
        $directory = realpath($this->appsDirectory);

        if ($directory === false || !is_dir($directory)) {
            return DiscoveryResult::createEmpty();
        }

        $root = $this->psr4Map->findAutoloadRootContaining($directory) ?? throw AppException::createForReason(
            AppError::DirectoryNotAutoloadable,
            directory: $directory,
        );
        $apps = [];
        $templates = [];
        $unmarked = [];
        $unloadable = [];
        $classesByKey = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php')->sortByName() as $file) {
            try {
                $class = $this->findClassForFile($root, $file);
            } catch (Throwable $e) {
                $unloadable[] = new UnloadableAppFile($file->getRealPath() ?: $file->getPathname(), $e);

                continue;
            }

            if ($class === null) {
                continue;
            }

            $automation = $class->findAutomation();

            if ($automation === null) {
                if ($class->isTemplate()) {
                    $class->requireRunnableApp('#[AppTemplate]');
                    $templates[] = $class->getName();
                } elseif ($class->isRunnableApp()) {
                    $unmarked[] = new UnmarkedApp($class->getName());
                }

                continue;
            }

            $app = new DiscoveredApp($class->getName(), $this->validateAppId($class, $automation));
            $key = $app->id->toEnvironmentKey();

            if (isset($classesByKey[$key])) {
                throw AppException::createForReason(AppError::DuplicateId, appId: $app->id->value, firstClass: $classesByKey[$key], secondClass: $app->class);
            }

            $classesByKey[$key] = $app->class;
            $apps[] = $app;
        }

        return new DiscoveryResult(
            DiscoveredAppCollection::fromApps($apps),
            UnmarkedAppCollection::fromList($unmarked),
            UnloadableAppFileCollection::fromList($unloadable),
            $templates,
        );
    }

    private function findClassForFile(AutoloadRoot $root, SplFileInfo $file): ?AppClassReflection
    {
        $path = $file->getRealPath();
        $class = $path === false ? null : $root->findClassNameForFile($path);

        return $class === null || !class_exists($class) ? null : new AppClassReflection(new ReflectionClass($class));
    }

    /** @throws AppException */
    private function validateAppId(AppClassReflection $class, Automation $automation): AppId
    {
        $class->requireRunnableApp('#[Automation]');

        return AppId::tryFromString($automation->id) ?? throw AppException::createForReason(AppError::IdInvalid, className: $class->getName(), appId: $automation->id);
    }
}
