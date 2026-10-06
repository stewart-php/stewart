<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Http;

use Amp\Socket\InternetAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Health\ProbeReport;
use Stewart\Runtime\Health\ProbeReportCodec;
use Stewart\Runtime\Health\ProbeStatus;
use Stewart\Runtime\Http\AmpHttpListener;
use Stewart\Runtime\Http\HttpListenerRole;
use Stewart\Runtime\Http\ProbeRequestHandler;
use Stewart\Runtime\Tests\Fixtures\Health\StubProbeReporter;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\ByteStream\buffer;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

#[CoversClass(AmpHttpListener::class)]
#[CoversClass(ProbeRequestHandler::class)]
final class AmpHttpListenerTest extends TestCase
{
    private StubProbeReporter $reporter;

    private RecordingLogger $logger;

    private AmpHttpListener $server;

    /** @var int<0, 65535> */
    private int $port;

    protected function setUp(): void
    {
        $this->reporter = new StubProbeReporter();
        $this->logger = new RecordingLogger();
        $this->port = self::findFreePort();
        $codec = new ProbeReportCodec(ProbeReportCodec::createProbeReportWireMapper());
        $this->server = new AmpHttpListener(
            new HttpListenAddress('127.0.0.1', $this->port),
            new ProbeRequestHandler($this->reporter, $codec, $this->logger),
            HttpListenerRole::Probe,
            $this->logger,
        );
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testLivenessAnswersAlive(): void
    {
        $response = $this->sendRequest('GET', '/healthz');

        self::assertStringStartsWith('HTTP/1.1 200', $response);
        self::assertStringContainsStringIgnoringCase('content-type: application/json', $response);
        self::assertStringEndsWith('{"status":"alive","reasons":[]}', $response);
    }

    public function testUnreadyDaemonAnswers503WithReasons(): void
    {
        $this->reporter->readiness = new ProbeReport(ProbeStatus::NotReady, ['Home Assistant is connecting']);

        $response = $this->sendRequest('GET', '/readyz');

        self::assertStringStartsWith('HTTP/1.1 503', $response);
        self::assertStringEndsWith('{"status":"not_ready","reasons":["Home Assistant is connecting"]}', $response);
    }

    public function testReadyDaemonAnswers200(): void
    {
        $this->reporter->readiness = new ProbeReport(ProbeStatus::Ready, []);

        self::assertStringStartsWith('HTTP/1.1 200', $this->sendRequest('GET', '/readyz'));
    }

    public function testHeadRequestIsAnswered(): void
    {
        self::assertStringStartsWith('HTTP/1.1 200', $this->sendRequest('HEAD', '/healthz'));
    }

    public function testUnknownPathIsNotFound(): void
    {
        self::assertStringStartsWith('HTTP/1.1 404', $this->sendRequest('GET', '/metrics'));
    }

    public function testPostIsRefused(): void
    {
        self::assertStringStartsWith('HTTP/1.1 405', $this->sendRequest('POST', '/healthz'));
    }

    public function testFailingReporterAnswers500AndLogs(): void
    {
        $this->reporter->failing = true;

        self::assertStringStartsWith('HTTP/1.1 500', $this->sendRequest('GET', '/readyz'));
        self::assertCount(1, $this->logger->listMessagesAt('error'));
    }

    public function testStopReleasesThePort(): void
    {
        $this->server->stop();

        $rebound = listen(new InternetAddress('127.0.0.1', $this->port));
        $rebound->close();
        $this->server->start();

        self::assertStringStartsWith('HTTP/1.1 200', $this->sendRequest('GET', '/healthz'));
    }

    private function sendRequest(string $method, string $path): string
    {
        $socket = connect('tcp://127.0.0.1:' . $this->port);
        $socket->write(\sprintf("%s %s HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n", $method, $path));

        return buffer($socket);
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
