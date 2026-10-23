<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Http\AmpHttpListener;
use Stewart\Runtime\Http\BearerTokenCheck;
use Stewart\Runtime\Http\HttpListenerRole;
use Stewart\Runtime\Http\MetricsRequestHandler;
use Stewart\Runtime\Http\UnauthorizedRequestWarnings;
use Stewart\Runtime\Tests\Fixtures\Http\AdminApiFixture;
use Stewart\Runtime\Tests\Fixtures\Http\LocalHttpRequests;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(MetricsRequestHandler::class)]
#[CoversClass(BearerTokenCheck::class)]
#[CoversClass(UnauthorizedRequestWarnings::class)]
final class HttpMetricsListenerTest extends TestCase
{
    private const string TOKEN = 'metrics-secret';

    private AdminApiFixture $fixture;

    private RecordingLogger $logger;

    private ?AmpHttpListener $server = null;

    private LocalHttpRequests $requests;

    protected function setUp(): void
    {
        $this->fixture = new AdminApiFixture();
        $this->logger = new RecordingLogger();
        $this->requests = LocalHttpRequests::createOnFreePort();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->fixture->stopEverything();
    }

    public function testMetricsWithoutATokenAnswerAnyone(): void
    {
        $this->startListener(null);

        $response = $this->requests->sendRequest('GET', '/metrics');

        self::assertStringStartsWith('HTTP/1.1 200', $response);
        self::assertStringContainsStringIgnoringCase('content-type: text/plain; version=0.0.4; charset=utf-8', $response);
        self::assertStringContainsString('stewart_app_paused{app="porch",worker="0"} 1', LocalHttpRequests::extractBody($response));
    }

    public function testMetricsWithATokenAnswerItsBearer(): void
    {
        $this->startListener(self::TOKEN);

        self::assertStringStartsWith('HTTP/1.1 200', $this->requests->sendRequest('GET', '/metrics', self::TOKEN));
    }

    public function testMissingOrWrongTokenIsUnauthorized(): void
    {
        $this->startListener(self::TOKEN);

        foreach ([null, 'admin-secret'] as $token) {
            $response = $this->requests->sendRequest('GET', '/metrics', $token);

            self::assertStringStartsWith('HTTP/1.1 401', $response);
            self::assertStringContainsStringIgnoringCase('www-authenticate: Bearer', $response);
        }

        self::assertSame(['HTTP request refused: no valid bearer token'], $this->logger->listMessagesAt('warning'));
    }

    public function testOnlyMetricsAreServed(): void
    {
        $this->startListener(null);

        self::assertStringStartsWith('HTTP/1.1 404', $this->requests->sendRequest('GET', '/api/apps'));
        self::assertStringStartsWith('HTTP/1.1 404', $this->requests->sendRequest('GET', '/healthz'));
    }

    public function testPostIsNotAllowed(): void
    {
        $this->startListener(null);

        self::assertStringStartsWith('HTTP/1.1 405', $this->requests->sendRequest('POST', '/api/apps/demo/pause'));
        self::assertFalse($this->fixture->registry->isPaused(new AppId('demo')));
    }

    private function startListener(?string $token): void
    {
        $handler = new MetricsRequestHandler(
            $this->fixture->metricsExporter,
            new BearerTokenCheck($token),
            new UnauthorizedRequestWarnings(HttpListenerRole::Metrics, $this->logger),
            $this->logger,
        );
        $this->server = new AmpHttpListener(new HttpListenAddress('127.0.0.1', $this->requests->port), $handler, HttpListenerRole::Metrics, $this->logger);
        $this->server->start();
    }
}
