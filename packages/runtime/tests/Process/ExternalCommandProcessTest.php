<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Process;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\NullCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Deploy\AmpExternalCommandRunner;
use Stewart\Runtime\Exception\DeployError;
use Stewart\Testing\Exception\AssertsReason;

use function Amp\async;
use function Amp\delay;

#[CoversClass(AmpExternalCommandRunner::class)]
final class ExternalCommandProcessTest extends TestCase
{
    use AssertsReason;

    private const string SLEEP_LONGER_THAN_ANY_LIMIT = 'sleep 30';

    public function testOutputStreamsAndExitCodeComeBack(): void
    {
        $result = new AmpExternalCommandRunner()->runCommand(['sh', '-c', 'echo out; echo err >&2; exit 3'], Duration::seconds(10), new NullCancellation());

        self::assertSame(3, $result->exitCode);
        self::assertSame("out\n", $result->output);
        self::assertSame("err\n", $result->errorOutput);
    }

    public function testLargeErrorOutputDoesNotStallTheCommand(): void
    {
        $result = new AmpExternalCommandRunner()->runCommand(['sh', '-c', 'head -c 1000000 /dev/zero | tr "\0" x >&2'], Duration::seconds(10), new NullCancellation());

        self::assertTrue($result->isSuccessful());
        self::assertSame(1000000, \strlen($result->errorOutput));
    }

    public function testCommandOverItsLimitTimesOut(): void
    {
        $this->assertThrowsReason(
            DeployError::CommandTimedOut,
            static fn() => new AmpExternalCommandRunner()->runCommand(['sh', '-c', self::SLEEP_LONGER_THAN_ANY_LIMIT], Duration::milliseconds(200), new NullCancellation()),
        );
    }

    public function testCancellationStopsTheCommand(): void
    {
        $stop = new DeferredCancellation();
        $running = async(static fn() => new AmpExternalCommandRunner()->runCommand(['sh', '-c', self::SLEEP_LONGER_THAN_ANY_LIMIT], Duration::seconds(10), $stop->getCancellation()));

        delay(0.2);
        $stop->cancel();

        $this->expectException(CancelledException::class);
        $running->await();
    }
}
