<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\Http\DisabledProbeListener;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Health\ProbeReportCodec;
use Stewart\Runtime\Http\HttpProbeServer;
use Stewart\Runtime\Http\ProbeListenerFactory;
use Stewart\Runtime\Http\ProbeRequestHandler;
use Stewart\Runtime\Tests\Fixtures\Health\StubProbeReporter;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(ProbeListenerFactory::class)]
final class ProbeListenerFactoryTest extends TestCase
{
    public function testUnsetListenCreatesDisabledListener(): void
    {
        self::assertInstanceOf(DisabledProbeListener::class, $this->createFactory(new HttpConfig(null))->createProbeListener());
    }

    public function testListenAddressCreatesHttpServer(): void
    {
        $factory = $this->createFactory(new HttpConfig(new HttpListenAddress('127.0.0.1', 8080)));

        self::assertInstanceOf(HttpProbeServer::class, $factory->createProbeListener());
    }

    private function createFactory(HttpConfig $http): ProbeListenerFactory
    {
        $logger = new RecordingLogger();
        $codec = new ProbeReportCodec(ProbeReportCodec::createProbeReportWireMapper());

        return new ProbeListenerFactory($http, new ProbeRequestHandler(new StubProbeReporter(), $codec, $logger), $logger);
    }
}
