<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Protocol\ControlProtocol;
use Stewart\Runtime\Control\Protocol\Frame\Bye;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\Rejected;
use Stewart\Runtime\Control\Protocol\Frame\RequestFailed;
use Stewart\Runtime\Control\Protocol\Frame\ResetAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\Protocol\Frame\Welcome;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;
use Stewart\Runtime\Tests\Fixtures\Wire\VersionedGoldenSet;

#[CoversClass(FrameCodec::class)]
// Set UPDATE_GOLDEN=1 to write tests/Fixtures/Control/Frames/<type>.json; a changed golden needs a VERSION bump first.
final class ControlFrameGoldenTest extends TestCase
{
    public function testEveryFrameMatchesItsGolden(): void
    {
        $codec = new FrameCodec(FrameCodec::createControlWireMapper());
        $encoded = [];

        foreach ([
            new Hello('golden-token', 'stewart status'),
            new SnapshotRequest(),
            new PauseAppRequest('porch'),
            new ResumeAppRequest('porch'),
            new ResetAppRequest('porch'),
            new Welcome(ControlProtocol::VERSION),
            new Rejected('the token does not match control.token'),
            new SnapshotFrame(new StubSnapshotSource()->takeSnapshot()),
            new CommandResult(true, 'App porch paused.', 'Not saved: persistence.url is not set, so this lasts until the daemon restarts.'),
            new RequestFailed('unknown', 'No automation with ID "ghost".'),
            new Bye('request answered'),
        ] as $frame) {
            $line = $codec->encodeFrame($frame);
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertIsString($decoded['type'] ?? null);
            $encoded[$decoded['type'] . '.json'] = $line;
        }

        new VersionedGoldenSet(__DIR__ . '/../../../Fixtures/Control/Frames', 'welcome.json', ['protocol'], ControlProtocol::VERSION, 'ControlProtocol::VERSION')
            ->assertEveryGoldenMatches($encoded);
    }
}
