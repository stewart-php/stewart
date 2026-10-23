<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Runtime\Config\AppOverride;
use Stewart\Runtime\Config\Collection\AppOverrideCollection;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Exception\AppException;
use Stewart\Support\Text\ClosestNameFinder;

// Loads the classes that apps.<id>.class names, so it runs only where app classes may load: never in the broker.
final readonly class AppInstanceResolver
{
    public function __construct(private ClosestNameFinder $names) {}

    /** @throws AppException */
    public function resolveInstances(DiscoveryResult $discovered, AppOverrideCollection $overrides): DiscoveryResult
    {
        $appsByKey = [];

        foreach ($discovered->apps as $app) {
            $appsByKey[$app->id->toEnvironmentKey()] = $app;
        }

        $instances = [];

        foreach ($overrides as $override) {
            if ($override->class === null) {
                continue;
            }

            $key = $override->id->toEnvironmentKey();
            $taken = $appsByKey[$key] ?? null;

            if ($taken !== null) {
                throw $taken->origin === AppOrigin::Scanned
                    ? AppException::createForReason(AppError::InstanceIdTaken, appId: $override->id->value, className: $override->class, takenBy: $taken->class)
                    : AppException::createForReason(AppError::InstanceIdsCollide, appId: $taken->id->value, otherAppId: $override->id->value);
            }

            $instance = new DiscoveredApp($this->requireInstanceClass($override, $override->class, $discovered), $override->id, AppOrigin::Configured);
            $appsByKey[$key] = $instance;
            $instances[] = $instance;
        }

        return $instances === [] ? $discovered : $discovered->withConfiguredApps($instances);
    }

    /**
     * @return class-string
     * @throws AppException
     */
    private function requireInstanceClass(AppOverride $override, string $className, DiscoveryResult $discovered): string
    {
        $class = AppClassReflection::findForClassName($className) ?? throw AppException::instanceClassMissing(
            $override->id,
            $className,
            $this->names->findClosestName($className, $this->listKnownClasses($discovered)),
        );

        if ($class->findAutomation() !== null) {
            $class->requireRunnableApp('#[Automation]');
        } elseif ($class->isTemplate()) {
            $class->requireRunnableApp('#[AppTemplate]');
        } else {
            throw AppException::createForReason(AppError::InstanceClassNotMarked, appId: $override->id->value, className: $className);
        }

        return $class->getName();
    }

    /** @return list<string> */
    private function listKnownClasses(DiscoveryResult $discovered): array
    {
        return [...$discovered->templateClasses, ...$discovered->apps->mapToList(static fn(DiscoveredApp $app): string => $app->class)];
    }
}
