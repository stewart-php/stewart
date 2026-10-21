<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use Amp\ByteStream\StreamException;
use Amp\DeferredCancellation;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Console\StatusWatch;
use Stewart\Runtime\Control\Protocol\Status\RuntimeSnapshot;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Exception\ControlException;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(StatusWatch::class)]
final class StatusWatchTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    private DeferredCancellation $stop;

    /** @var list<string> */
    private array $drawn = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->stop = new DeferredCancellation();
        $this->drawn = [];
    }

    public function testDaemonThatComesBackIsDrawnAgainAfterTheOutage(): void
    {
        /** @var list<Closure(): RuntimeSnapshot> $answers */
        $answers = [
            static fn(): RuntimeSnapshot => throw new StreamException('Connection refused'),
            static fn(): RuntimeSnapshot => throw ControlException::createForReason(ControlError::BrokerHungUp),
            static fn(): RuntimeSnapshot => new StubSnapshotSource()->takeSnapshot(),
        ];
        $startedAt = $this->timers->clock->getMonotonicTime();

        $this->watch(static function () use (&$answers): RuntimeSnapshot {
            $answer = array_shift($answers) ?? self::fail('Asked for more frames than the test serves.');

            return $answer();
        });

        self::assertSame(['outage: Connection refused', 'outage', 'frame'], $this->drawn);
        self::assertSame(4, (int) $this->timers->clock->getMonotonicTime()->elapsedSince($startedAt)->toSeconds(), 'One interval between frames.');
    }

    public function testRefusedConnectionEndsTheWatch(): void
    {
        $this->assertThrowsReason(ControlError::ConnectionRejected, fn() => $this->watch(static fn(): RuntimeSnapshot => throw ControlException::createForReason(ControlError::ConnectionRejected, reason: 'token')));

        self::assertSame([], $this->drawn);
    }

    /** @param Closure(): RuntimeSnapshot $fetchSnapshot */
    private function watch(Closure $fetchSnapshot): void
    {
        new StatusWatch($this->timers)->watchStatus(
            $fetchSnapshot,
            function (): void {
                $this->drawn[] = 'frame';
                $this->stop->cancel();
            },
            function (string $outage): void {
                $this->drawn[] = $outage === 'Connection refused' ? 'outage: ' . $outage : 'outage';
            },
            Duration::seconds(2),
            $this->stop->getCancellation(),
        );
    }
}
