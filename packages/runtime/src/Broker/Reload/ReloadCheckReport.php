<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\App\DiscoveredApp;
use Stewart\Runtime\App\DiscoveryResult;
use Stewart\Runtime\App\UnmarkedApp;

// What reload:check found, from a process that could load the apps fresh; it carries no option values.
final readonly class ReloadCheckReport
{
    /**
     * @param list<ReloadCheckApp> $apps
     * @param list<class-string> $unmarkedClasses
     * @param list<string> $errors
     */
    public function __construct(
        #[ListOf(ReloadCheckApp::class)]
        public array $apps,
        #[ListOf('string')]
        public array $unmarkedClasses,
        #[ListOf('string')]
        public array $errors,
        public string $configFingerprint,
    ) {}

    public static function fromDiscovery(DiscoveryResult $discovered, string $configFingerprint): self
    {
        return new self(
            $discovered->apps->mapToList(static fn(DiscoveredApp $app): ReloadCheckApp => new ReloadCheckApp($app->id->value, $app->class)),
            $discovered->unmarked->mapToList(static fn(UnmarkedApp $app): string => $app->class),
            [],
            $configFingerprint,
        );
    }

    /** @param list<string> $errors */
    public static function failed(array $errors, string $configFingerprint = ''): self
    {
        return new self([], [], $errors, $configFingerprint);
    }

    public function findFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    public function restoreDiscovery(): DiscoveryResult
    {
        return new DiscoveryResult(
            DiscoveredAppCollection::fromApps(array_map(static fn(ReloadCheckApp $app): DiscoveredApp => new DiscoveredApp($app->class, new AppId($app->id)), $this->apps)),
            UnmarkedAppCollection::fromList(array_map(static fn(string $class): UnmarkedApp => new UnmarkedApp($class), $this->unmarkedClasses)),
            UnloadableAppFileCollection::empty(),
        );
    }
}
