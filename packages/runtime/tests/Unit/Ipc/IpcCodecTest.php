<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Ipc\Wire\Collection\EncodedStateChangeCollection;
use Stewart\Runtime\Ipc\Wire\EncodedStateChange;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\Wire\IpcMessageCatalog;
use Stewart\Runtime\Ipc\Wire\StateChangesFragment;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Tests\Fixtures\Ipc\IpcMessageSamples;
use Stewart\Runtime\Tests\Fixtures\Wire\BrokenDurationConverter;
use Stewart\Testing\Exception\AssertsReason;

#[CoversNamespace('Stewart\Runtime\Ipc\Wire')]
#[CoversClass(TransportException::class)]
#[CoversClass(ServiceCallFailed::class)]
final class IpcCodecTest extends TestCase
{
    use AssertsReason;

    private const int AT = 1_758_700_000_123_456;

    private IpcCodec $codecs;

    protected function setUp(): void
    {
        $this->codecs = IpcCodec::createForWorkerBootstrap();
    }

    #[DataProvider('provideRoundTripMessages')]
    public function testEveryMessageSurvivesTheWire(object $sent, object $received): void
    {
        $decoded = $this->codecs->decodeMessage($this->codecs->encodeMessage($sent));

        if ($received instanceof StateChangeBatch) {
            // A decoded batch keeps plain changes, an outgoing one their encodings; only the changes compare.
            self::assertInstanceOf(StateChangeBatch::class, $decoded);
            self::assertEquals($received->changes->collection, $decoded->changes->collection);

            return;
        }

        self::assertEquals($received, $decoded);
    }

    public function testEveryMessageTypeHasASample(): void
    {
        $catalog = IpcMessageCatalog::scanMessageDirectory();
        $tags = array_map($catalog->findTagForClass(...), $catalog->listMessageClasses());
        $sampled = array_keys(iterator_to_array(self::provideRoundTripMessages()));
        sort($sampled);

        self::assertSame($tags, $sampled);
    }

    public function testFrameNamesItsType(): void
    {
        self::assertSame('{"t":"unsubscribe","m":{"subscription_id":"w0:3"}}', $this->codecs->encodeMessage(new Unsubscribe(new SubscriptionId('w0:3'))));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideUndecodableFrames(): iterable
    {
        yield 'not json' => ['{', '?'];
        yield 'not an object' => ['[1]', '?'];
        yield 'unknown type' => ['{"t":"dance","m":{}}', 'dance'];
        yield 'no body' => ['{"t":"ping"}', 'ping'];
        yield 'wrong field type' => ['{"t":"ping","m":{"nonce":"one","sent_us":1}}', 'ping'];
        yield 'unknown enum value' => ['{"t":"subscribe","m":{"subscription_id":"a","scope":"b","kind":"weather","selector":{"kind":"any"}}}', 'subscribe'];
        yield 'enum value of wrong type' => ['{"t":"subscribe","m":{"subscription_id":"a","scope":"b","kind":1,"selector":{"kind":"any"}}}', 'subscribe'];
        yield 'unknown selector kind' => ['{"t":"subscribe","m":{"subscription_id":"a","scope":"b","kind":"event","selector":{"kind":"fuzzy"}}}', 'subscribe'];
        yield 'invalid regex' => ['{"t":"subscribe","m":{"subscription_id":"a","scope":"b","kind":"event","selector":{"kind":"regex","regex":"/("}}}', 'subscribe'];
        yield 'context not scalar' => ['{"t":"service_call_error","m":{"correlation_id":"a","message":"x","details":{"class":"X","reason":"x","context":{"k":{"a":1}}}}}', 'service_call_error'];
        yield 'missing key' => ['{"t":"unsubscribe","m":{}}', 'unsubscribe'];
    }

    #[DataProvider('provideUndecodableFrames')]
    public function testUndecodableFrameNamesItsType(string $frame, string $type): void
    {
        try {
            $this->codecs->decodeMessage($frame);
            self::fail('The frame should have been refused.');
        } catch (TransportException $e) {
            self::assertSame(TransportError::UndecodableFrame, $e->reason);
            self::assertSame($type, $e->context['messageType'] ?? null);
        }
    }

    public function testDecoderBugIsUndecodableFrame(): void
    {
        $codec = new IpcCodec(new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([new BrokenDurationConverter()]))), IpcMessageCatalog::scanMessageDirectory());

        $e = $this->assertThrowsReason(TransportError::UndecodableFrame, fn() => $codec->decodeMessage($this->codecs->encodeMessage(new Shutdown('stopping', Duration::seconds(5)))));

        self::assertSame('shutdown', $e->context['messageType'] ?? null);
    }

    public function testBootstrapFromAnotherProtocolIsRefusedAsSuch(): void
    {
        $frame = str_replace('"protocol":' . IpcCodec::PROTOCOL_VERSION, '"protocol":' . (IpcCodec::PROTOCOL_VERSION + 1), $this->codecs->encodeMessage(IpcMessageSamples::createBootstrap()));

        $this->assertThrowsReason(TransportError::ProtocolMismatch, fn() => $this->codecs->decodeMessage($frame));
    }

    public function testPayloadJsonCannotCarryIsUnencodable(): void
    {
        $this->assertThrowsReason(TransportError::Unencodable, fn() => $this->codecs->encodeMessage(new Publish('t', \NAN, ResourceScope::forApp(new AppId('demo')), Instant::fromEpochMicroseconds(self::AT))));
    }

    public function testStoreSecretStaysOutOfDumps(): void
    {
        $store = new StoreSettings('redis://:hunter2@valkey:6379/0', 'stewart', Duration::seconds(5), Duration::seconds(5));

        self::assertStringNotContainsString('hunter2', print_r($store, true));
    }

    public function testServiceCallFailureSurvivesTheWire(): void
    {
        $failure = ServiceCallException::rejected('light', 'turn_on', 'Entity not found', 'not_found');

        $message = $this->codecs->decodeMessage($this->codecs->encodeMessage(ServiceCallFailed::fromException(new CorrelationId('0:1'), $failure)));
        self::assertInstanceOf(ServiceCallFailed::class, $message);
        $rebuilt = $message->toException();

        self::assertSame(ServiceCallError::Rejected, $rebuilt->reason);
        self::assertSame($failure->getMessage(), $rebuilt->getMessage());
        self::assertSame($failure->context, $rebuilt->context);
        self::assertSame(ServiceCallError::Rejected, $message->getReason());
    }

    public function testUnknownCallReasonDegradesToUnreachable(): void
    {
        $message = new ServiceCallFailed(new CorrelationId('0:1'), 'something odd', new ExceptionDetails(ServiceCallException::class, 'melted', null));

        self::assertSame(ServiceCallError::Unreachable, $message->getReason());
        self::assertSame(ServiceCallError::Unreachable, $message->toException()->reason);
    }

    public function testStateChangeIsEncodedOnceForEveryBatch(): void
    {
        $change = new EncodedStateChange(new StateChange(new EntityId('light.hall'), null, new EntityState(new EntityId('light.hall'), 'on')));
        $mapper = IpcCodec::createIpcWireMapper();

        self::assertSame($change->encodeToJson($mapper), $change->encodeToJson(IpcCodec::createIpcWireMapper()));
        self::assertSame('[' . $change->encodeToJson($mapper) . ']', StateChangesFragment::fromEncodedChanges(EncodedStateChangeCollection::fromChanges([$change]))->encodeToJson($mapper));
    }

    public function testNumericAttributeKeysComeBackAsStrings(): void
    {
        $frame = '{"t":"state_snapshot","m":{"states":[{"entity_id":"sensor.x","state":"1","attributes":{"123":"a"},"last_changed_at_us":null,"last_updated_at_us":null,"context":null}],"revision":1}}';

        $snapshot = $this->codecs->decodeMessage($frame);
        self::assertInstanceOf(StateSnapshot::class, $snapshot);

        self::assertSame(['123'], array_map(strval(...), array_keys($snapshot->states->collection->find(new EntityId('sensor.x'))->attributes ?? [])));
        self::assertStringContainsString('"attributes":{"123":"a"}', $this->codecs->encodeMessage(new StateSnapshot($snapshot->states, 1)));
    }

    public function testEmptySnapshotTravels(): void
    {
        self::assertEquals(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::empty()), 1), $this->codecs->decodeMessage($this->codecs->encodeMessage(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::empty()), 1))));
    }

    public function testMalformedStateNamesItsPath(): void
    {
        $frame = '{"t":"state_changes","m":{"changes":[{"entity_id":"a.b","from":null,"to":{"entity_id":"a.b","state":"on","attributes":{},"last_changed_at_us":"now","last_updated_at_us":null,"context":null},"fired_at_us":null,"context":null,"origin":"live"}]}}';

        try {
            $this->codecs->decodeMessage($frame);
            self::fail('The frame should have been refused.');
        } catch (TransportException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(JsonShapeException::class, $previous);
            self::assertSame('changes.0.to.last_changed_at_us', $previous->context['key'] ?? null);
        }
    }

    /** @return iterable<string, array{object, object}> */
    public static function provideRoundTripMessages(): iterable
    {
        foreach (IpcMessageSamples::listSamplesByTag() as $tag => $sample) {
            yield $tag => [$sample->sent, $sample->received];
        }
    }

    public function testEveryMessageCanBeDescribed(): void
    {
        $mapper = IpcCodec::createIpcWireMapper();

        foreach (IpcMessageCatalog::scanMessageDirectory()->listMessageClasses() as $class) {
            self::assertSame($class, $mapper->shapes->resolveClassShape($class)->class);
        }
    }
}
