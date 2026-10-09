<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentCommand;
use Stewart\Client\Component\ComponentCommandAction;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\SessionReplaced;

#[CoversClass(ComponentEventDecoder::class)]
final class ComponentEventDecoderTest extends TestCase
{
    public function testSessionReplacedIsDecoded(): void
    {
        self::assertInstanceOf(SessionReplaced::class, new ComponentEventDecoder()->decodeSessionEvent(['type' => 'session_replaced']));
    }

    public function testUnknownTypeIsSkipped(): void
    {
        self::assertNull(new ComponentEventDecoder()->decodeSessionEvent(['type' => 'sentence']));
        self::assertNull(new ComponentEventDecoder()->decodeSessionEvent([]));
    }

    public function testCommandIsDecoded(): void
    {
        $command = new ComponentEventDecoder()->decodeSessionEvent(self::createCommandEvent());

        self::assertInstanceOf(ComponentCommand::class, $command);
        self::assertSame('3f2b9c0e8d7a4f61', $command->commandId);
        self::assertSame('lights', $command->appId->value);
        self::assertSame('night_mode', $command->key->value);
        self::assertSame(ComponentCommandAction::TurnOn, $command->action);
        self::assertSame('user-1', $command->context->userId);
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('provideMalformedCommandFields')]
    public function testMalformedCommandIsSkipped(array $override): void
    {
        self::assertNull(new ComponentEventDecoder()->decodeSessionEvent([...self::createCommandEvent(), ...$override]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideMalformedCommandFields(): iterable
    {
        yield 'command id' => [['command_id' => 7]];
        yield 'app id' => [['app' => 'Lights']];
        yield 'key' => [['key' => 'night-mode']];
        yield 'action' => [['action' => 'toggle']];
        yield 'data' => [['data' => 'on']];
        yield 'context' => [['context' => null]];
    }

    /** @return array<string, mixed> */
    private static function createCommandEvent(): array
    {
        return [
            'type' => 'command',
            'command_id' => '3f2b9c0e8d7a4f61',
            'app' => 'lights',
            'key' => 'night_mode',
            'action' => 'turn_on',
            'data' => [],
            'context' => ['id' => 'context-1', 'parent_id' => null, 'user_id' => 'user-1'],
        ];
    }
}
