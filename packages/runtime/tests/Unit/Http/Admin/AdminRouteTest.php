<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Http\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Http\Admin\AdminAction;
use Stewart\Runtime\Http\Admin\AdminRoute;

#[CoversClass(AdminRoute::class)]
#[CoversClass(AdminAction::class)]
final class AdminRouteTest extends TestCase
{
    public function testAppsPathListsApps(): void
    {
        self::assertEquals(new AdminRoute(AdminAction::ListApps, null), AdminRoute::fromPath('/api/apps'));
    }

    public function testAppPathShowsThatApp(): void
    {
        self::assertEquals(new AdminRoute(AdminAction::ShowApp, 'porch-lights'), AdminRoute::fromPath('/api/apps/porch-lights'));
    }

    public function testActionPathsCarryAction(): void
    {
        self::assertEquals(new AdminRoute(AdminAction::PauseApp, 'demo'), AdminRoute::fromPath('/api/apps/demo/pause'));
        self::assertEquals(new AdminRoute(AdminAction::ResumeApp, 'demo'), AdminRoute::fromPath('/api/apps/demo/resume'));
        self::assertEquals(new AdminRoute(AdminAction::ResetApp, 'demo'), AdminRoute::fromPath('/api/apps/demo/reset'));
    }

    public function testMetricsPathShowsMetrics(): void
    {
        self::assertEquals(new AdminRoute(AdminAction::ShowMetrics, null), AdminRoute::fromPath('/metrics'));
        self::assertSame(['GET', 'HEAD'], AdminAction::ShowMetrics->listAllowedMethods());
    }

    public function testEncodedAppIdIsDecoded(): void
    {
        self::assertSame('a b', AdminRoute::fromPath('/api/apps/a%20b')?->appId);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownPaths(): iterable
    {
        yield 'root' => ['/'];
        yield 'api root' => ['/api'];
        yield 'trailing slash' => ['/api/apps/'];
        yield 'nested unknown' => ['/api/apps/demo/unknown'];
        yield 'other resource' => ['/api/workers'];
        yield 'action trailing slash' => ['/api/apps/demo/pause/'];
        yield 'action on list' => ['/api/apps/pause/now'];
        yield 'metrics trailing slash' => ['/metrics/'];
        yield 'metrics nested' => ['/metrics/apps'];
    }

    #[DataProvider('unknownPaths')]
    public function testUnknownPathHasNoRoute(string $path): void
    {
        self::assertNull(AdminRoute::fromPath($path));
    }

    public function testReadRoutesAllowOnlyGetAndHead(): void
    {
        $route = new AdminRoute(AdminAction::ListApps, null);

        self::assertTrue($route->allowsMethod('GET'));
        self::assertTrue($route->allowsMethod('HEAD'));
        self::assertFalse($route->allowsMethod('POST'));
    }

    public function testActionRoutesAllowOnlyPost(): void
    {
        $route = new AdminRoute(AdminAction::PauseApp, 'demo');

        self::assertTrue($route->allowsMethod('POST'));
        self::assertFalse($route->allowsMethod('GET'));
    }
}
