<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Tests\Fixtures\Ipc\IpcMessageSamples;
use Stewart\Runtime\Tests\Fixtures\Wire\VersionedGoldenSet;

#[CoversClass(IpcCodec::class)]
// Set UPDATE_GOLDEN=1 to write tests/Fixtures/Ipc/<tag>.json; a changed golden needs a PROTOCOL_VERSION bump first.
final class WireGoldenTest extends TestCase
{
    public function testEveryMessageMatchesItsGolden(): void
    {
        $codec = IpcCodec::createForWorkerBootstrap();
        $encoded = [];

        foreach (IpcMessageSamples::listSamplesByTag() as $tag => $sample) {
            $encoded[$tag . '.json'] = $codec->encodeMessage($sample->sent);
        }

        new VersionedGoldenSet(__DIR__ . '/../../Fixtures/Ipc', 'bootstrap.json', ['m', 'protocol'], IpcCodec::PROTOCOL_VERSION, 'IpcCodec::PROTOCOL_VERSION')
            ->assertEveryGoldenMatches($encoded);
    }
}
