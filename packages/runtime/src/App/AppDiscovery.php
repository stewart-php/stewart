<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use ReflectionClass;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Automation;
use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\Config\AutoloadRoot;
use Stewart\Runtime\Config\Psr4Map;
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

        $root = $this->psr4Map->findAutoloadRootContaining($directory) ?? throw AppException::directoryNotAutoloadable($directory);
        $apps = [];
        $unmarked = [];
        $unloadable = [];
        $classesByKey = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php')->sortByName() as $file) {
            try {
                $reflection = $this->findClassForFile($root, $file);
            } catch (Throwable $e) {
                $unloadable[] = new UnloadableAppFile($file->getRealPath() ?: $file->getPathname(), $e);

                continue;
            }

            if ($reflection === null) {
                continue;
            }

            $automation = $reflection->getAttributes(Automation::class)[0] ?? null;

            if ($automation === null) {
                if ($this->isRunnableApp($reflection)) {
                    $unmarked[] = new UnmarkedApp($reflection->getName());
                }

                continue;
            }

            $app = new DiscoveredApp($reflection->getName(), $this->validateAppId($reflection, $automation->newInstance()));
            $key = $app->id->toEnvironmentKey();

            if (isset($classesByKey[$key])) {
                throw AppException::duplicateId($app->id, $classesByKey[$key], $app->class);
            }

            $classesByKey[$key] = $app->class;
            $apps[] = $app;
        }

        return new DiscoveryResult(
            DiscoveredAppCollection::fromApps($apps),
            UnmarkedAppCollection::fromApps($unmarked),
            UnloadableAppFileCollection::fromFiles($unloadable),
        );
    }

    /** @return ReflectionClass<object>|null */
    private function findClassForFile(AutoloadRoot $root, SplFileInfo $file): ?ReflectionClass
    {
        $path = $file->getRealPath();
        $class = $path === false ? null : $root->findClassNameForFile($path);

        return $class === null || !class_exists($class) ? null : new ReflectionClass($class);
    }

    /** @param ReflectionClass<object> $reflection */
    private function isRunnableApp(ReflectionClass $reflection): bool
    {
        return $reflection->isInstantiable() && $reflection->implementsInterface(App::class);
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @throws AppException
     */
    private function validateAppId(ReflectionClass $reflection, Automation $automation): AppId
    {
        $class = $reflection->getName();

        if (!$reflection->implementsInterface(App::class)) {
            throw AppException::notAnApp($class, App::class);
        }

        if (!$reflection->isInstantiable()) {
            throw AppException::notInstantiable($class);
        }

        return AppId::tryFromString($automation->id) ?? throw AppException::idInvalid($class, $automation->id);
    }
}
