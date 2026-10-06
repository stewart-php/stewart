<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Health\ProbeReport;
use Stewart\Runtime\Health\ProbeReportCodec;
use Stewart\Runtime\Health\ProbeStatus;
use Stewart\Runtime\Health\ReadinessVerdict;

#[CoversClass(ProbeReportCodec::class)]
#[CoversClass(ProbeReport::class)]
#[CoversClass(ProbeStatus::class)]
final class ProbeReportCodecTest extends TestCase
{
    public function testAliveReportEncodesEmptyReasons(): void
    {
        self::assertSame('{"status":"alive","reasons":[]}', $this->createCodec()->encodeReport(ProbeReport::alive()));
    }

    public function testReadyVerdictEncodesAsReady(): void
    {
        $report = ProbeReport::fromVerdict(new ReadinessVerdict([]));

        self::assertSame('{"status":"ready","reasons":[]}', $this->createCodec()->encodeReport($report));
        self::assertTrue($report->status->isHealthy());
    }

    public function testUnreadyVerdictEncodesItsReasons(): void
    {
        $report = ProbeReport::fromVerdict(new ReadinessVerdict(['Home Assistant is connecting', 'worker 1 is quarantined']));

        self::assertSame(
            '{"status":"not_ready","reasons":["Home Assistant is connecting","worker 1 is quarantined"]}',
            $this->createCodec()->encodeReport($report),
        );
        self::assertFalse($report->status->isHealthy());
    }

    private function createCodec(): ProbeReportCodec
    {
        return new ProbeReportCodec(ProbeReportCodec::createProbeReportWireMapper());
    }
}
