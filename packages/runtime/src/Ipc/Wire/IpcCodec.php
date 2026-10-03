<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use JsonException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\DurationConverter;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\StringIdentifierConverter;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Support\Json\JsonDecoder;
use Throwable;

final readonly class IpcCodec
{
    public const int PROTOCOL_VERSION = 14;

    private const string UNKNOWN_TYPE = '?';

    public function __construct(
        private WireMapper $ipcWireMapper,
        private IpcMessageCatalog $messageCatalog,
    ) {}

    /** @throws TransportException */
    public static function createForWorkerBootstrap(): self
    {
        return new self(self::createIpcWireMapper(), IpcMessageCatalog::scanMessageDirectory());
    }

    public static function createIpcWireMapper(): WireMapper
    {
        return new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([
            new EpochInstantConverter(),
            new DurationConverter(),
            new SelectorConverter(),
            new MqttMessageConverter(),
            new ExceptionDetailsConverter(),
            StringIdentifierConverter::createForEntityIds(),
            StringIdentifierConverter::createForAppIds(),
            new StringIdentifierConverter(SubscriptionId::class, 'a subscription id', SubscriptionId::fromString(...)),
            new StringIdentifierConverter(CorrelationId::class, 'a correlation id', CorrelationId::fromString(...)),
            new WorkerIdConverter(),
            new StringIdentifierConverter(ResourceScope::class, 'an app id or "@shared"', ResourceScope::tryFromWireValue(...)),
        ])));
    }

    /** @throws TransportException */
    public function encodeMessage(object $message): string
    {
        $tag = $this->messageCatalog->findTagForClass($message::class) ?? throw TransportException::messageTypeMissing($message::class);

        try {
            return '{"t":"' . $tag . '","m":' . $this->ipcWireMapper->encodeObject($message) . '}';
        } catch (TransportException $e) {
            throw $e;
        } catch (JsonException|StewartException $e) {
            throw TransportException::unencodable($message::class, $e);
        }
    }

    /** @throws TransportException */
    public function decodeMessage(string $frame): object
    {
        try {
            $data = JsonDecoder::decodeJson($frame);
        } catch (JsonException $e) {
            throw TransportException::undecodableFrame(self::UNKNOWN_TYPE, $e->getMessage(), $e);
        }

        $tag = \is_array($data) && \is_string($data['t'] ?? null) ? $data['t'] : self::UNKNOWN_TYPE;
        $body = \is_array($data) ? ($data['m'] ?? null) : null;
        $class = $this->messageCatalog->findClassForTag($tag);

        if ($class === null || !\is_array($body)) {
            throw TransportException::undecodableFrame($tag, $class === null ? 'unknown message type' : 'the frame has no message body');
        }

        if ($class === Bootstrap::class && ($body['protocol'] ?? null) !== self::PROTOCOL_VERSION) {
            throw TransportException::protocolMismatch(self::PROTOCOL_VERSION, \is_int($body['protocol'] ?? null) ? $body['protocol'] : 0);
        }

        try {
            return $this->ipcWireMapper->decodeObject($class, $body);
        } catch (Throwable $e) {
            throw TransportException::undecodableFrame($tag, $e->getMessage(), $e);
        }
    }
}
