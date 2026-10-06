<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Config\HttpListenAddress;
use Stewart\Runtime\Health\ProbeReportCodec;
use Stewart\Runtime\Http\AmpHttpListener;
use Stewart\Runtime\Http\HttpListenerFactory;
use Stewart\Runtime\Http\ProbeRequestHandler;
use Stewart\Runtime\Tests\Fixtures\Health\StubProbeReporter;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(HttpListenerFactory::class)]
final class HttpListenerFactoryTest extends TestCase
{
    public function testUnsetListenCreatesNoListener(): void
    {
        self::assertTrue($this->createFactory(new HttpConfig(null))->createHttpListeners()->isEmpty());
    }

    public function testListenAddressCreatesProbeListener(): void
    {
        $factory = $this->createFactory(new HttpConfig(new HttpListenAddress('127.0.0.1', 8080)));

        self::assertInstanceOf(AmpHttpListener::class, $factory->createHttpListeners()->getFirst());
    }

    private function createFactory(HttpConfig $http): HttpListenerFactory
    {
        $logger = new RecordingLogger();
        $codec = new ProbeReportCodec(ProbeReportCodec::createProbeReportWireMapper());

        return new HttpListenerFactory($http, new ProbeRequestHandler(new StubProbeReporter(), $codec, $logger), $logger);
    }
}
