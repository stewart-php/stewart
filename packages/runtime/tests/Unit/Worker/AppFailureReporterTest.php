<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\AppFailureReporter;
use Stewart\Runtime\Worker\HandlerFailureSampler;
use Stewart\Runtime\Worker\StderrFallback;

#[CoversClass(AppFailureReporter::class)]
final class AppFailureReporterTest extends TestCase
{
    private NullTransport $transport;

    private AppActivityCounters $counters;

    private AppFailureReporter $failures;

    protected function setUp(): void
    {
        $this->transport = new NullTransport();
        $this->counters = new AppActivityCounters();
        $this->failures = new AppFailureReporter($this->transport, new StderrFallback(new WorkerId(0)), $this->counters, new HandlerFailureSampler());
    }

    public function testEveryFailureIsCountedEvenWhenNotSent(): void
    {
        for ($failure = 0; $failure < 120; ++$failure) {
            $this->failures->report(self::createScope(), AppFailurePhase::Handler, new RuntimeException('boom'), 'subscription w0:1');
        }

        $sent = array_values(array_filter($this->transport->sent, static fn(object $message): bool => $message instanceof AppFailed));

        self::assertSame([1, 100], array_map(static fn(AppFailed $failure): int => $failure->occurrence, $sent));
        self::assertSame(120, $this->counters->findOrCreateActivityForScope(self::createScope())->failures);
    }

    public function testLifecycleFailureIsSentAndCounted(): void
    {
        $this->failures->report(self::createScope(), AppFailurePhase::Initialize, new RuntimeException('boom'));

        self::assertCount(1, $this->transport->sent);
        self::assertSame(1, $this->counters->findOrCreateActivityForScope(self::createScope())->failures);
    }

    private static function createScope(): ResourceScope
    {
        return ResourceScope::forApp(new AppId('demo'));
    }
}
