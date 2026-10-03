<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Snapshot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\HaClientFactory;
use Stewart\Codegen\Exception\CodegenError;
use Stewart\Codegen\Snapshot\HaClientSnapshotFetcher;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

#[CoversClass(HaClientSnapshotFetcher::class)]
final class HaClientSnapshotFetcherTest extends TestCase
{
    use AssertsReason;

    public function testStartingHomeAssistantIsRefused(): void
    {
        $socket = self::createHomeAssistant(['state' => 'STARTING']);

        $this->assertThrowsReason(CodegenError::HomeAssistantNotRunning, static fn() => self::fetchSnapshot($socket));

        self::assertTrue($socket->isClosed());
        self::assertSame([], $socket->listSentOfType('get_states'));
    }

    public function testRunningHomeAssistantIsFetched(): void
    {
        $snapshot = self::fetchSnapshot(self::createHomeAssistant(['state' => 'RUNNING']));

        self::assertSame('2026.9.0', $snapshot->haVersion);
        self::assertSame(['light.hall'], $snapshot->states->listEntityIds()->toStrings());
    }

    public function testMissingCoreStateIsAccepted(): void
    {
        self::assertSame(['light.hall'], self::fetchSnapshot(self::createHomeAssistant([]))->states->listEntityIds()->toStrings());
    }

    private static function fetchSnapshot(FakeWebsocketConnection $socket): Snapshot
    {
        $fetcher = new HaClientSnapshotFetcher(new HaClientFactory(new ManualTimers(), new FakeWebsocketConnector($socket)));

        return $fetcher->fetchSnapshot(
            new ConnectionConfig(
                url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
                token: 'test-token',
                connectTimeout: Duration::seconds(1),
                commandTimeout: Duration::seconds(1),
                heartbeatInterval: null,
            ),
            new NullLogger(),
        );
    }

    /** @param array<string, string> $coreState */
    private static function createHomeAssistant(array $coreState): FakeWebsocketConnection
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $socket->replyWhenSent('get_config', self::createResult(['time_zone' => 'UTC', ...$coreState]));
        $socket->replyWhenSent('get_states', self::createResult([['entity_id' => 'light.hall', 'state' => 'on', 'attributes' => []]]));
        $socket->replyWhenSent('config/entity_registry/list', self::createResult([]));
        $socket->replyWhenSent('get_services', self::createResult([]));

        return $socket;
    }

    /**
     * @param array<array-key, mixed> $result
     * @return array<string, mixed>
     */
    private static function createResult(array $result): array
    {
        return ['type' => 'result', 'success' => true, 'result' => $result];
    }
}
