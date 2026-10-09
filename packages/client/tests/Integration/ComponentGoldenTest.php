<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Integration;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ComponentSessionEvent;
use Stewart\Client\Component\ComponentSessionRequest;
use Stewart\Client\Component\ComponentVersion;
use Stewart\Client\Component\SessionReplaced;
use Stewart\Client\Connection\Command\Component\GetComponentVersion;
use Stewart\Client\Connection\Command\Component\SubscribeComponentSession;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Client\Tests\Fixtures\Component\ComponentGolden;
use Stewart\Client\Tests\Fixtures\FakeHaServer;
use Stewart\Contracts\Time\Duration;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(GetComponentVersion::class)]
#[CoversClass(SubscribeComponentSession::class)]
#[CoversClass(ComponentVersion::class)]
#[CoversClass(ComponentEventDecoder::class)]
final class ComponentGoldenTest extends TestCase
{
    use AssertsReason;

    private const float WAIT_SECONDS = 5;

    private FakeHaServer $server;

    private ?HaClient $client = null;

    protected function setUp(): void
    {
        $this->server = FakeHaServer::start();
    }

    protected function tearDown(): void
    {
        $this->client?->close();
        $this->server->stop();
    }

    public function testVersionMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('version');
        $this->server->replayGolden($golden);

        $version = $this->connectClient()->findComponentVersion();

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/version'));
        self::assertSame('0.9.0', $version?->componentVersion);
        self::assertSame(1, $version->protocol);
    }

    public function testSessionSubscribeMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('session-subscribe');
        $this->server->replayGolden($golden);

        $this->connectClient()->subscribeComponentSession(self::createSessionRequest(), static function (): void {});

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/session/subscribe'));
    }

    public function testProtocolMismatchGoldenIsRefusal(): void
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('session-subscribe.protocol-mismatch'));
        $client = $this->connectClient();

        $exception = $this->assertThrowsReason(
            HaClientError::CommandRejected,
            static fn() => $client->subscribeComponentSession(self::createSessionRequest(), static function (): void {}),
        );

        self::assertInstanceOf(HaClientException::class, $exception);
        self::assertSame('protocol_mismatch', $exception->findErrorCode());
    }

    public function testSessionReplacedGoldenIsDecoded(): void
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('session-subscribe'));
        /** @var DeferredFuture<ComponentSessionEvent> $received */
        $received = new DeferredFuture();

        $subscriptionId = $this->connectClient()->subscribeComponentSession(
            self::createSessionRequest(),
            static function (ComponentSessionEvent $event) use ($received): void {
                $received->complete($event);
            },
        );
        $this->server->pushEvent($subscriptionId, ComponentGolden::loadGolden('event-session-replaced')->requireEvent());

        self::assertInstanceOf(SessionReplaced::class, $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS)));
    }

    private static function createSessionRequest(): ComponentSessionRequest
    {
        return new ComponentSessionRequest(ComponentInstance::parse('default'), '0.9.0', Duration::seconds(5));
    }

    /** @return list<array<array-key, mixed>> */
    private function listReceivedWithoutIds(string $type): array
    {
        return array_map(static function (array $command): array {
            unset($command['id']);

            return $command;
        }, $this->server->listReceivedCommands($type));
    }

    private function connectClient(): HaClient
    {
        $this->client = HaClient::fromConnectionConfig(
            new ConnectionConfig(
                url: $this->server->getUrl(),
                token: 'test-token',
                connectTimeout: Duration::seconds(5),
                commandTimeout: Duration::seconds(5),
                heartbeatInterval: null,
            ),
            new RevoltTimers(),
        );
        $this->client->connect();

        return $this->client;
    }
}
