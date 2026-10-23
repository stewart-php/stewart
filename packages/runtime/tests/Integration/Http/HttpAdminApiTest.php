<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Http\Admin\AdminRequestHandler;
use Stewart\Runtime\Http\Admin\Response\AdminAppView;
use Stewart\Runtime\Http\AmpHttpListener;
use Stewart\Runtime\Http\BearerTokenCheck;
use Stewart\Runtime\Http\HttpListenerRole;
use Stewart\Runtime\Http\UnauthorizedRequestWarnings;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Http\AdminApiFixture;
use Stewart\Runtime\Tests\Fixtures\Http\LocalHttpRequests;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(AdminRequestHandler::class)]
#[CoversClass(RuntimeAdmin::class)]
#[CoversClass(AdminApiCodec::class)]
#[CoversClass(AdminAppView::class)]
#[CoversClass(BearerTokenCheck::class)]
#[CoversClass(UnauthorizedRequestWarnings::class)]
final class HttpAdminApiTest extends TestCase
{
    private const string TOKEN = 'admin-secret';

    private const string NOT_SAVED = 'Not saved: persistence.url is not set, so this lasts until the daemon restarts.';

    private const string DEMO_JSON = '{"id":"demo","state":null,"paused":false,"pause":null,"config_pause_override":null,"last_handled_at":null,"next_run_at":null}';

    private const string PORCH_JSON = '{"id":"porch","state":null,"paused":true,"pause":{"since":"%s","source":"config"},"config_pause_override":null,"last_handled_at":null,"next_run_at":null}';

    private AdminApiFixture $fixture;

    private AmpHttpListener $server;

    private RecordingLogger $logger;

    private LocalHttpRequests $requests;

    protected function setUp(): void
    {
        $this->fixture = new AdminApiFixture();
        $fixture = $this->fixture;
        $this->logger = new RecordingLogger();
        $this->requests = LocalHttpRequests::createOnFreePort();
        $this->server = new AmpHttpListener(
            new HttpListenAddress('127.0.0.1', $this->requests->port),
            new AdminRequestHandler(
                $fixture->admin,
                $fixture->codec,
                $this->logger,
                new BearerTokenCheck(self::TOKEN),
                new UnauthorizedRequestWarnings(HttpListenerRole::Admin, $this->logger),
            ),
            HttpListenerRole::Admin,
            $this->logger,
        );
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        $this->fixture->stopEverything();
    }

    public function testMetricsPointToTheMetricsPort(): void
    {
        $response = $this->sendRequest('GET', '/metrics', self::TOKEN);

        self::assertStringStartsWith('HTTP/1.1 404', $response);
        self::assertSame(
            '{"reason":"not_found","message":"Metrics are served on the http.metrics.listen port, not the admin port."}',
            LocalHttpRequests::extractBody($response),
        );
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $response = $this->sendRequest('GET', '/api/apps', null);

        self::assertStringStartsWith('HTTP/1.1 401', $response);
        self::assertStringContainsStringIgnoringCase('www-authenticate: Bearer', $response);
        self::assertStringContainsString('"reason":"unauthorized"', LocalHttpRequests::extractBody($response));
    }

    public function testWrongTokenIsUnauthorized(): void
    {
        self::assertStringStartsWith('HTTP/1.1 401', $this->sendRequest('GET', '/api/apps', 'wrong'));
    }

    public function testUnknownPathIsUnauthorizedWithoutToken(): void
    {
        self::assertStringStartsWith('HTTP/1.1 401', $this->sendRequest('GET', '/nowhere', null));
    }

    public function testListShowsLoadedAppsWithPauseState(): void
    {
        $response = $this->sendRequest('GET', '/api/apps');

        self::assertStringStartsWith('HTTP/1.1 200', $response);
        self::assertStringContainsStringIgnoringCase('content-type: application/json', $response);
        $since = $this->fixture->clock->getNow()->toIso8601();
        self::assertJsonStringEqualsJsonString(
            \sprintf('{"apps":[%s,%s]}', self::DEMO_JSON, \sprintf(self::PORCH_JSON, $since)),
            LocalHttpRequests::extractBody($response),
        );
    }

    public function testShowAnswersOneApp(): void
    {
        $response = $this->sendRequest('GET', '/api/apps/porch');

        self::assertStringStartsWith('HTTP/1.1 200', $response);
        self::assertJsonStringEqualsJsonString(\sprintf(self::PORCH_JSON, $this->fixture->clock->getNow()->toIso8601()), LocalHttpRequests::extractBody($response));
    }

    public function testUnknownAppIsNotFound(): void
    {
        $response = $this->sendRequest('GET', '/api/apps/ghost');

        self::assertStringStartsWith('HTTP/1.1 404', $response);
        self::assertStringContainsString('"reason":"unknown"', LocalHttpRequests::extractBody($response));
    }

    public function testMalformedAppIdIsNotFound(): void
    {
        self::assertStringStartsWith('HTTP/1.1 404', $this->sendRequest('GET', '/api/apps/Not%20An%20Id'));
    }

    public function testDisabledAppIsConflict(): void
    {
        $response = $this->sendRequest('GET', '/api/apps/retired');

        self::assertStringStartsWith('HTTP/1.1 409', $response);
        self::assertStringContainsString('"reason":"disabled"', LocalHttpRequests::extractBody($response));
    }

    public function testUnknownPathIsNotFound(): void
    {
        self::assertStringStartsWith('HTTP/1.1 404', $this->sendRequest('GET', '/api/workers'));
    }

    public function testPostOnReadRouteIsNotAllowed(): void
    {
        $response = $this->sendRequest('POST', '/api/apps');

        self::assertStringStartsWith('HTTP/1.1 405', $response);
        self::assertStringContainsStringIgnoringCase('allow: GET, HEAD', $response);
    }

    public function testPauseIsReportedAsChangedOnce(): void
    {
        $first = $this->sendRequest('POST', '/api/apps/demo/pause');
        $second = $this->sendRequest('POST', '/api/apps/demo/pause');

        self::assertStringStartsWith('HTTP/1.1 200', $first);
        self::assertJsonStringEqualsJsonString(\sprintf('{"changed":true,"message":"App demo paused.","warning":"%s"}', self::NOT_SAVED), LocalHttpRequests::extractBody($first));
        self::assertJsonStringEqualsJsonString(\sprintf('{"changed":false,"message":"App demo was already paused.","warning":"%s"}', self::NOT_SAVED), LocalHttpRequests::extractBody($second));
        self::assertSame(AppPauseSource::Http, $this->fixture->registry->findPause(new AppId('demo'))?->source);
    }

    public function testResumeLiftsConfigPause(): void
    {
        $response = $this->sendRequest('POST', '/api/apps/porch/resume');

        self::assertJsonStringEqualsJsonString(\sprintf('{"changed":true,"message":"App porch resumed.","warning":"%s"}', self::NOT_SAVED), LocalHttpRequests::extractBody($response));
        self::assertFalse($this->fixture->registry->isPaused(new AppId('porch')));
    }

    public function testResetRemovesOverride(): void
    {
        $this->sendRequest('POST', '/api/apps/porch/resume');

        $response = $this->sendRequest('POST', '/api/apps/porch/reset');

        self::assertJsonStringEqualsJsonString(
            \sprintf('{"changed":true,"message":"App porch pause override removed; config keeps it paused.","warning":"%s"}', self::NOT_SAVED),
            LocalHttpRequests::extractBody($response),
        );
        self::assertTrue($this->fixture->registry->isPaused(new AppId('porch')));
    }

    public function testPauseOfUnknownAppIsNotFound(): void
    {
        self::assertStringStartsWith('HTTP/1.1 404', $this->sendRequest('POST', '/api/apps/ghost/pause'));
    }

    public function testPauseOfDisabledAppIsConflict(): void
    {
        self::assertStringStartsWith('HTTP/1.1 409', $this->sendRequest('POST', '/api/apps/retired/pause'));
    }

    public function testGetOnActionRouteIsNotAllowed(): void
    {
        $response = $this->sendRequest('GET', '/api/apps/demo/pause');

        self::assertStringStartsWith('HTTP/1.1 405', $response);
        self::assertStringContainsStringIgnoringCase('allow: POST', $response);
    }

    public function testPauseWithoutTokenChangesNothing(): void
    {
        self::assertStringStartsWith('HTTP/1.1 401', $this->sendRequest('POST', '/api/apps/demo/pause', null));
        self::assertFalse($this->fixture->registry->isPaused(new AppId('demo')));
    }

    public function testWorkerRestartIsReportedAsBegun(): void
    {
        $this->fixture->startWorker();

        $response = $this->sendRequest('POST', '/api/workers/0/restart');

        self::assertStringStartsWith('HTTP/1.1 200', $response);
        self::assertJsonStringEqualsJsonString('{"changed":true,"message":"Worker 0 is restarting.","warning":null}', LocalHttpRequests::extractBody($response));
    }

    public function testRestartOfUnknownWorkerIsNotFound(): void
    {
        foreach (['/api/workers/7/restart', '/api/workers/porch/restart'] as $path) {
            $response = $this->sendRequest('POST', $path);

            self::assertStringStartsWith('HTTP/1.1 404', $response);
            self::assertStringContainsString('"reason":"worker_unknown"', LocalHttpRequests::extractBody($response));
        }
    }

    public function testReloadIsAnsweredThroughTheRuntime(): void
    {
        $response = $this->sendRequest('POST', '/api/reload');

        self::assertStringStartsWith('HTTP/1.1 200', $response);
        self::assertJsonStringEqualsJsonString('{"changed":false,"message":"Stewart is not running its apps yet, so there is nothing to reload.","warning":null}', LocalHttpRequests::extractBody($response));
        self::assertStringStartsWith('HTTP/1.1 405', $this->sendRequest('GET', '/api/reload'));
    }

    public function testGetOnWorkerRestartIsNotAllowed(): void
    {
        self::assertStringStartsWith('HTTP/1.1 405', $this->sendRequest('GET', '/api/workers/0/restart'));
    }

    public function testWorkerRestartNeedsToken(): void
    {
        self::assertStringStartsWith('HTTP/1.1 401', $this->sendRequest('POST', '/api/workers/0/restart', null));
    }

    public function testOnlyTheFirstRefusalIsLoggedUntilTheHundredth(): void
    {
        $this->sendRequest('GET', '/api/apps', null);
        $this->sendRequest('GET', '/api/apps', 'wrong');

        self::assertSame(['HTTP request refused: no valid bearer token'], $this->logger->listMessagesAt('warning'));
    }

    private function sendRequest(string $method, string $path, ?string $token = self::TOKEN): string
    {
        return $this->requests->sendRequest($method, $path, $token);
    }
}
