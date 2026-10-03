<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use SensitiveParameter;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Config\Collection\AppOverrideCollection;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Logging\LogFormat;
use Stewart\Runtime\Model\LogLevel;

final readonly class StewartConfig
{
    public function __construct(
        public int $workers,
        public LogLevel $logLevel,
        public LogFormat $logFormat,
        public Duration $shutdownGrace,
        public int $workerEventBuffer,
        public int $workerStateBatch,
        public int $subscriptionBuffer,
        public ?ConnectionConfig $homeAssistant,
        public BackoffPolicy $reconnectBackoff,
        public SupervisionConfig $supervision,
        public ServiceCallPolicy $serviceCalls,
        public ?PersistenceConfig $persistence,
        public ?MqttConfig $mqtt,
        public ControlConfig $control,
        public CodegenConfig $codegen,
        public AppOverrideCollection $apps,
    ) {}

    /**
     * @throws ConfigurationException
     * @throws IdentifierException
     */
    public static function fromSection(#[SensitiveParameter] ConfigSection $config): self
    {
        $homeAssistant = $config->findSection('home_assistant');

        return new self(
            workers: $config->readInt('workers'),
            logLevel: $config->readParsedValue('log_level', LogLevel::from(...)),
            logFormat: $config->readParsedValue('log_format', LogFormat::from(...)),
            shutdownGrace: $config->readDuration('shutdown_grace'),
            workerEventBuffer: $config->readInt('worker_event_buffer'),
            workerStateBatch: $config->readInt('worker_state_batch'),
            subscriptionBuffer: $config->readInt('subscription_buffer'),
            homeAssistant: $homeAssistant === null ? null : self::readConnectionConfig($homeAssistant),
            reconnectBackoff: $config->readSection('reconnect')->readBackoff(''),
            supervision: SupervisionConfig::fromSection($config->readSection('supervision')),
            serviceCalls: ServiceCallPolicy::fromSection($config->readSection('service_calls')),
            persistence: PersistenceConfig::fromSection($config->readSection('persistence')),
            mqtt: MqttConfig::fromSection($config->readSection('mqtt')),
            control: ControlConfig::fromSection($config->readSection('control')),
            codegen: CodegenConfig::fromSection($config->readSection('codegen')),
            apps: AppOverrideCollection::keyedByAppId($config->readSection('apps')->mapSubsections(AppOverride::fromSection(...))),
        );
    }

    /** @throws ConfigurationException */
    public function requireHomeAssistant(): ConnectionConfig
    {
        return $this->homeAssistant ?? throw ConfigurationException::homeAssistantMissing();
    }

    /** @throws ConfigurationException */
    private static function readConnectionConfig(#[SensitiveParameter] ConfigSection $homeAssistant): ConnectionConfig
    {
        $oneMillisecond = Duration::milliseconds(1);

        return new ConnectionConfig(
            url: $homeAssistant->readParsedValue('url', HomeAssistantUrl::parse(...)),
            token: $homeAssistant->readString('token'),
            connectTimeout: $homeAssistant->readDuration('connect_timeout', $oneMillisecond),
            commandTimeout: $homeAssistant->readDuration('command_timeout', $oneMillisecond),
            heartbeatInterval: $homeAssistant->readOptionalDuration('heartbeat_interval', Duration::seconds(1))->findDuration(),
            messageSizeLimit: $homeAssistant->readInt('message_size_limit'),
            frameSizeLimit: $homeAssistant->readInt('frame_size_limit'),
            heartbeatMissedLimit: $homeAssistant->readInt('heartbeat_missed_limit'),
        );
    }
}
