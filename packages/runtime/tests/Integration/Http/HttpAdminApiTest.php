<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Http;

use Amp\Socket\InternetAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Http\Admin\AdminApiCodec;
use Stewart\Runtime\Http\Admin\AdminRequestHandler;
use Stewart\Runtime\Http\Admin\AppsAdminApi;
use Stewart\Runtime\Http\Admin\Response\AdminAppView;
use Stewart\Runtime\Http\AmpHttpListener;
use Stewart\Runtime\Http\HttpListenerRole;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Http\AdminApiFixture;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\ByteStream\buffer;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

#[CoversClass(AdminRequestHandler::class)]
#[CoversClass(AppsAdminApi::class)]
#[CoversClass(AdminApiCodec::class)]
#[CoversClass(AdminAppView::class)]
final class HttpAdminApiTest extends TestCase
{
    private const string TOKEN = 'admin-secret';

    private const string NOT_SAVED = 'Not saved: persistence.url is not set, so this lasts until the daemon restarts.';

    private const string DEMO_JSON = '{"id":"demo","state":null,"paused":false,"pause":null,"config_pause_override":null}';

    private const string PORCH_JSON = '{"id":"porch","state":null,"paused":true,"pause":{"since":"%s","source":"config"},"config_pause_override":null}';

    private AdminApiFixture $fixture;

    private AmpHttpListener $server;

    /** @var int<0, 65535> */
    private int $port;

    protected function setUp(): void
    {
        $this->fixture = new AdminApiFixture();
        $fixture = $this->fixture;
        $logger = new RecordingLogger();
        $this->port = self::findFreePort();
        $this->server = new AmpHttpListener(
            new HttpListenAddress('127.0.0.1', $this->port),
            new AdminRequestHandler($fixture->api, $fixture->codec, $logger, self::TOKEN),
            HttpListenerRole::Admin,
            $logger,
        );
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $response = $this->sendRequest('GET', '/api/apps', null);

        self::assertStringStartsWith('HTTP/1.1 401', $response);
        self::assertStringContainsStringIgnoringCase('www-authenticate: Bearer', $response);
        self::assertStringContainsString('"reason":"unauthorized"', self::extractBody($response));
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
            self::extractBody($response),
        );
    }

    public function testShowAnswersOneApp(): void
    {
        $response = $this->sendRequest('GET', '/api/apps/porch');

        self::assertStringStartsWith('HTTP/1.1 200', $response);
        self::assertJsonStringEqualsJsonString(\sprintf(self::PORCH_JSON, $this->fixture->clock->getNow()->toIso8601()), self::extractBody($response));
    }

    public function testUnknownAppIsNotFound(): void
    {
        $response = $this->sendRequest('GET', '/api/apps/ghost');

        self::assertStringStartsWith('HTTP/1.1 404', $response);
        self::assertStringContainsString('"reason":"unknown"', self::extractBody($response));
    }

    public function testMalformedAppIdIsNotFound(): void
    {
        self::assertStringStartsWith('HTTP/1.1 404', $this->sendRequest('GET', '/api/apps/Not%20An%20Id'));
    }

    public function testDisabledAppIsConflict(): void
    {
        $response = $this->sendRequest('GET', '/api/apps/retired');

        self::assertStringStartsWith('HTTP/1.1 409', $response);
        self::assertStringContainsString('"reason":"disabled"', self::extractBody($response));
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
        self::assertJsonStringEqualsJsonString(\sprintf('{"changed":true,"message":"App demo paused.","warning":"%s"}', self::NOT_SAVED), self::extractBody($first));
        self::assertJsonStringEqualsJsonString(\sprintf('{"changed":false,"message":"App demo was already paused.","warning":"%s"}', self::NOT_SAVED), self::extractBody($second));
        self::assertSame(AppPauseSource::Http, $this->fixture->registry->findPause(new AppId('demo'))?->source);
    }

    public function testResumeLiftsConfigPause(): void
    {
        $response = $this->sendRequest('POST', '/api/apps/porch/resume');

        self::assertJsonStringEqualsJsonString(\sprintf('{"changed":true,"message":"App porch resumed.","warning":"%s"}', self::NOT_SAVED), self::extractBody($response));
        self::assertFalse($this->fixture->registry->isPaused(new AppId('porch')));
    }

    public function testResetRemovesOverride(): void
    {
        $this->sendRequest('POST', '/api/apps/porch/resume');

        $response = $this->sendRequest('POST', '/api/apps/porch/reset');

        self::assertJsonStringEqualsJsonString(
            \sprintf('{"changed":true,"message":"App porch pause override removed; config keeps it paused.","warning":"%s"}', self::NOT_SAVED),
            self::extractBody($response),
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

    private function sendRequest(string $method, string $path, ?string $token = self::TOKEN): string
    {
        $authorization = $token === null ? '' : \sprintf("Authorization: Bearer %s\r\n", $token);
        $socket = connect('tcp://127.0.0.1:' . $this->port);
        $socket->write(\sprintf("%s %s HTTP/1.1\r\nHost: localhost\r\n%sContent-Length: 0\r\nConnection: close\r\n\r\n", $method, $path, $authorization));

        return buffer($socket);
    }

    private static function extractBody(string $response): string
    {
        return explode("\r\n\r\n", $response, 2)[1] ?? '';
    }

    /** @return int<0, 65535> */
    private static function findFreePort(): int
    {
        $probe = listen('127.0.0.1:0');
        $address = $probe->getAddress();
        $probe->close();
        \assert($address instanceof InternetAddress);

        return $address->getPort();
    }
}
