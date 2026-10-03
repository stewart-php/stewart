<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use SensitiveParameter;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class MqttConfig
{
    private const string CLIENT_ID_PREFIX = 'stewart-';

    public function __construct(
        public MqttServerUrl $serverUrl,
        public string $clientId,
        public Duration $keepalive,
        public Duration $connectTimeout,
        public BackoffPolicy $reconnectBackoff,
        public int $outboundBuffer,
        public ?MqttMessage $will,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(#[SensitiveParameter] ConfigSection $mqtt): ?self
    {
        $url = $mqtt->findString('url');

        if ($url === null || $url === '') {
            return null;
        }

        $clientId = $mqtt->findString('client_id');

        return new self(
            serverUrl: $mqtt->readParsedValue('url', MqttServerUrl::parse(...)),
            clientId: $clientId === null || $clientId === '' ? self::CLIENT_ID_PREFIX . gethostname() : $clientId,
            keepalive: $mqtt->readDuration('keepalive', Duration::seconds(1)),
            connectTimeout: $mqtt->readDuration('connect_timeout', Duration::milliseconds(1)),
            reconnectBackoff: $mqtt->readBackoff('reconnect_'),
            outboundBuffer: $mqtt->readInt('outbound_buffer'),
            will: self::readWill($mqtt->readSection('will')),
        );
    }

    /** @throws ConfigurationException */
    private static function readWill(ConfigSection $will): ?MqttMessage
    {
        $topic = $will->findString('topic');

        if ($topic === null || $topic === '') {
            return null;
        }

        return $will->readParsedValue('topic', static fn(string $topic): MqttMessage => MqttMessage::createForPublish(
            $topic,
            $will->readString('payload'),
            MqttQos::from($will->readInt('qos')),
            $will->readBool('retain'),
        ));
    }
}
