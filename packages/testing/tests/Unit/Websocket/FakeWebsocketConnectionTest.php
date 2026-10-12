<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Websocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\Websocket\FakeWebsocketConnection;

#[CoversClass(FakeWebsocketConnection::class)]
final class FakeWebsocketConnectionTest extends TestCase
{
    public function testEverySendIsAnsweredWithItsId(): void
    {
        $socket = new FakeWebsocketConnection();
        $socket->answerEverySend('ping', static fn(array $command): array => ['type' => 'pong', 'echo' => $command['value'] ?? null]);

        $socket->sendText('{"id":1,"type":"ping","value":"a"}');
        $socket->sendText('{"id":2,"type":"ping","value":"b"}');

        self::assertSame('{"id":1,"type":"pong","echo":"a"}', self::receiveText($socket));
        self::assertSame('{"id":2,"type":"pong","echo":"b"}', self::receiveText($socket));
    }

    public function testNullAnswerLeavesTheReplyForLater(): void
    {
        $socket = new FakeWebsocketConnection();
        $socket->answerEverySend('ping', static fn(array $command): ?array => null);
        $socket->sendText('{"id":1,"type":"ping"}');

        $socket->queueFrame(['id' => 1, 'type' => 'pong']);

        self::assertSame('{"id":1,"type":"pong"}', self::receiveText($socket));
    }

    public function testOneShotReplyWinsOverTheStandingAnswer(): void
    {
        $socket = new FakeWebsocketConnection();
        $socket->answerEverySend('ping', static fn(array $command): array => ['type' => 'pong']);
        $socket->replyWhenSent('ping', ['type' => 'refused']);

        $socket->sendText('{"id":1,"type":"ping"}');
        $socket->sendText('{"id":2,"type":"ping"}');

        self::assertSame('{"id":1,"type":"refused"}', self::receiveText($socket));
        self::assertSame('{"id":2,"type":"pong"}', self::receiveText($socket));
    }

    private static function receiveText(FakeWebsocketConnection $socket): string
    {
        $message = $socket->receive();
        self::assertNotNull($message);

        return $message->buffer();
    }
}
