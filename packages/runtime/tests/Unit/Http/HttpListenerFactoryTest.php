<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpAdminConfig;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Config\HttpMetricsConfig;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Health\ProbeReportCodec;
use Stewart\Runtime\Http\AmpHttpListener;
use Stewart\Runtime\Http\HttpListenerFactory;
use Stewart\Runtime\Http\ProbeRequestHandler;
use Stewart\Runtime\Tests\Fixtures\Health\StubProbeReporter;
use Stewart\Runtime\Tests\Fixtures\Http\AdminApiFixture;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(HttpListenerFactory::class)]
final class HttpListenerFactoryTest extends TestCase
{
    use AssertsReason;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testUnsetListenCreatesNoListener(): void
    {
        self::assertTrue($this->createFactory(new HttpConfig(null, new HttpAdminConfig(null, null)))->createHttpListeners()->isEmpty());
    }

    public function testListenAddressCreatesProbeListener(): void
    {
        $factory = $this->createFactory(new HttpConfig(new HttpListenAddress('127.0.0.1', 8080), new HttpAdminConfig(null, null)));

        self::assertInstanceOf(AmpHttpListener::class, $factory->createHttpListeners()->getFirst());
    }

    public function testAdminListenAddsSecondListener(): void
    {
        $admin = new HttpAdminConfig(new HttpListenAddress('127.0.0.1', 8081), 'secret');
        $factory = $this->createFactory(new HttpConfig(new HttpListenAddress('127.0.0.1', 8080), $admin));

        self::assertCount(2, $factory->createHttpListeners());
    }

    public function testAdminListenWithoutTokenIsRefused(): void
    {
        $factory = $this->createFactory(new HttpConfig(null, new HttpAdminConfig(new HttpListenAddress('127.0.0.1', 8081), null)));

        $this->assertThrowsReason(ConfigurationError::AdminTokenMissing, $factory->createHttpListeners(...));
    }

    public function testMetricsListenAddsThirdListener(): void
    {
        $admin = new HttpAdminConfig(new HttpListenAddress('127.0.0.1', 8081), 'secret');
        $metrics = new HttpMetricsConfig(new HttpListenAddress('0.0.0.0', 9464), 'scrape');

        self::assertCount(3, $this->createFactory(new HttpConfig(new HttpListenAddress('127.0.0.1', 8080), $admin, $metrics))->createHttpListeners());
        self::assertSame([], $this->logger->listMessagesAt('info'));
    }

    public function testMetricsWithoutTokenAreNoted(): void
    {
        $metrics = new HttpMetricsConfig(new HttpListenAddress('0.0.0.0', 9464), null);

        self::assertCount(1, $this->createFactory(new HttpConfig(null, new HttpAdminConfig(null, null), $metrics))->createHttpListeners());
        self::assertSame(['The metrics port has no token, so anyone who reaches it reads the metrics'], $this->logger->listMessagesAt('info'));
    }

    public function testListenersSharingAnAddressAreRefused(): void
    {
        $metrics = new HttpMetricsConfig(new HttpListenAddress('127.0.0.1', 8080), null);
        $factory = $this->createFactory(new HttpConfig(new HttpListenAddress('0.0.0.0', 8080), new HttpAdminConfig(null, null), $metrics));

        $this->assertThrowsReason(ConfigurationError::HttpListenAddressShared, $factory->createHttpListeners(...));
    }

    private function createFactory(HttpConfig $http): HttpListenerFactory
    {
        $logger = $this->logger;
        $codec = new ProbeReportCodec(ProbeReportCodec::createProbeReportWireMapper());
        $admin = new AdminApiFixture();

        return new HttpListenerFactory($http, new ProbeRequestHandler(new StubProbeReporter(), $codec, $logger), $admin->admin, $admin->codec, $admin->metricsExporter, $logger);
    }
}
