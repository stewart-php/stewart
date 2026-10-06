<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpAdminConfig;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Config\HttpListenAddress;
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

        self::assertSame(2, $factory->createHttpListeners()->count());
    }

    public function testAdminListenWithoutTokenIsRefused(): void
    {
        $factory = $this->createFactory(new HttpConfig(null, new HttpAdminConfig(new HttpListenAddress('127.0.0.1', 8081), null)));

        $this->assertThrowsReason(ConfigurationError::AdminTokenMissing, $factory->createHttpListeners(...));
    }

    private function createFactory(HttpConfig $http): HttpListenerFactory
    {
        $logger = new RecordingLogger();
        $codec = new ProbeReportCodec(ProbeReportCodec::createProbeReportWireMapper());
        $admin = new AdminApiFixture();

        return new HttpListenerFactory($http, new ProbeRequestHandler(new StubProbeReporter(), $codec, $logger), $admin->api, $admin->codec, $logger);
    }
}
