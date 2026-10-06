<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\App;

use ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppCatalogResolver;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\AppSelection;
use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;
use Stewart\Runtime\App\DiscoveredApp;
use Stewart\Runtime\App\DiscoveryResult;
use Stewart\Runtime\App\UnloadableAppFile;
use Stewart\Runtime\Config\AppOverride;
use Stewart\Runtime\Config\Collection\AppOverrideCollection;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(AppCatalogResolver::class)]
#[CoversClass(AppSelection::class)]
final class AppCatalogResolverTest extends TestCase
{
    use AssertsReason;

    public function testDiscoveredAppRunsWithoutAnOverride(): void
    {
        $catalog = new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo', 'hall-light'), AppOverrideCollection::empty(), workers: 0);

        self::assertSame(['demo', 'hall-light'], self::listEnabledIds($catalog));
        $demo = $catalog->enabled->find(new AppId('demo'));
        self::assertNotNull($demo);
        self::assertSame([], $demo->options);
        self::assertNull($demo->worker);
        self::assertFalse($demo->startsPaused);
    }

    public function testPausedOverrideStartsAppPaused(): void
    {
        $catalog = new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo'), AppOverrideCollection::keyedByAppId([self::createOverride('demo', paused: true)]), workers: 0);

        self::assertTrue($catalog->enabled->find(new AppId('demo'))?->startsPaused);
    }

    public function testOverrideTunesItsApp(): void
    {
        $catalog = new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo'), AppOverrideCollection::keyedByAppId([self::createOverride('demo', worker: 1, options: ['room' => 'hall'])]), workers: 2);

        $demo = $catalog->enabled->find(new AppId('demo'));
        self::assertNotNull($demo);
        self::assertSame(1, $demo->worker);
        self::assertSame(['room' => 'hall'], $demo->options);
    }

    public function testDisabledAppIsLeftOutButStaysKnown(): void
    {
        $catalog = new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo', 'echo'), AppOverrideCollection::keyedByAppId([self::createOverride('echo', enabled: false)]), workers: 0);

        self::assertSame(['demo'], self::listEnabledIds($catalog));
        self::assertSame(['demo', 'echo'], $catalog->knownIds->toStrings());
    }

    public function testOnlySelectionRunsNamedAppsEvenDisabled(): void
    {
        $catalog = new AppCatalogResolver()->resolveCatalog(
            self::createDiscoveryResult('demo', 'echo', 'porch'),
            AppOverrideCollection::keyedByAppId([self::createOverride('echo', enabled: false)]),
            workers: 0,
            selection: new AppSelection(AppIdCollection::fromIds([new AppId('echo')])),
        );

        self::assertSame(['echo'], self::listEnabledIds($catalog));
        self::assertSame(['demo', 'echo', 'porch'], $catalog->knownIds->toStrings());
    }

    public function testOnlySelectionOfUnknownAppIsRejected(): void
    {
        $selection = new AppSelection(AppIdCollection::fromIds([new AppId('ghost')]));

        $e = $this->assertThrowsReason(ConfigurationError::AppSelectionUnknown, fn() => new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo'), AppOverrideCollection::empty(), workers: 0, selection: $selection));

        self::assertSame(['demo'], $e->context['knownAppIds'] ?? null);
    }

    public function testOverrideForNoAutomationIsRejected(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::AppUnknown, fn() => new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo'), AppOverrideCollection::keyedByAppId([self::createOverride('ghost')]), workers: 0));

        self::assertStringContainsString('Known automations: demo.', $e->getMessage());
    }

    public function testMisspelledOverrideNamesTheAutomation(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::AppNameMismatch, fn() => new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('hall-light'), AppOverrideCollection::keyedByAppId([self::createOverride('hall_light')]), workers: 0));

        self::assertStringContainsString('the automation ID is "hall-light"', $e->getMessage());
    }

    public function testUnmatchedOverrideIsKeptWhenAFileFailed(): void
    {
        $catalog = new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResultWithUnloadableFile('demo'), AppOverrideCollection::keyedByAppId([self::createOverride('porch')]), workers: 0);

        self::assertSame(['demo'], self::listEnabledIds($catalog));
        self::assertSame(['porch'], $catalog->unmatchedOverrideIds->toStrings());
    }

    public function testCollidingOverrideStillFailsWhenAFileFailed(): void
    {
        $this->assertThrowsReason(ConfigurationError::AppNameMismatch, fn() => new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResultWithUnloadableFile('hall-light'), AppOverrideCollection::keyedByAppId([self::createOverride('hall_light')]), workers: 0));
    }

    public function testPinBeyondAnExplicitPoolIsRejected(): void
    {
        $e = $this->assertThrowsReason(ConfigurationError::WorkerIndexOutOfRange, fn() => new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo'), AppOverrideCollection::keyedByAppId([self::createOverride('demo', worker: 2)]), workers: 2));

        self::assertStringContainsString('App "demo" is pinned to worker 2, but the highest worker index is 1.', $e->getMessage());
    }

    public function testAutoSizedPoolAcceptsAnyPin(): void
    {
        $catalog = new AppCatalogResolver()->resolveCatalog(self::createDiscoveryResult('demo'), AppOverrideCollection::keyedByAppId([self::createOverride('demo', worker: 7)]), workers: 0);

        self::assertSame(7, $catalog->enabled->find(new AppId('demo'))?->worker);
    }

    /** @return list<string> */
    private static function listEnabledIds(AppCatalog $catalog): array
    {
        return $catalog->enabled->mapToList(static fn(AppDefinition $definition): string => $definition->id->value);
    }

    /** @param non-empty-string ...$ids */
    private static function createDiscoveryResult(string ...$ids): DiscoveryResult
    {
        return new DiscoveryResult(
            DiscoveredAppCollection::fromApps(array_map(static fn(string $id): DiscoveredApp => new DiscoveredApp(Demo::class, new AppId($id)), array_values($ids))),
            UnmarkedAppCollection::empty(),
            UnloadableAppFileCollection::empty(),
        );
    }

    /** @param non-empty-string ...$ids */
    private static function createDiscoveryResultWithUnloadableFile(string ...$ids): DiscoveryResult
    {
        $discovered = self::createDiscoveryResult(...$ids);

        return new DiscoveryResult(
            $discovered->apps,
            $discovered->unmarked,
            UnloadableAppFileCollection::fromFiles([new UnloadableAppFile('/apps/Broken.php', new ParseError('syntax error'))]),
        );
    }

    /** @param array<string, mixed> $options */
    private static function createOverride(string $id, bool $enabled = true, ?int $worker = null, array $options = [], bool $paused = false): AppOverride
    {
        return new AppOverride(new AppId($id), $enabled, $paused, $worker, $options);
    }
}
