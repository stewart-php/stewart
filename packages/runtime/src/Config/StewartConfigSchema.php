<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Closure;
use InvalidArgumentException;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Logging\LogFormat;
use Stewart\Runtime\Model\LogLevel;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\NodeInterface;

final class StewartConfigSchema implements ConfigurationInterface
{
    public const string ROOT = 'stewart';

    public const string OFF = 'off';


    private const int DEFAULT_SIZE_LIMIT_BYTES = 64 * 1024 * 1024;

    private const string DOMAIN_KEY = '/\A[a-z0-9_]+\z/';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $this->addTopLevelSettings($root);
        $this->addHomeAssistantSection($root);
        $this->addReconnectSection($root);
        $this->addSupervisionSection($root);
        $this->addServiceCallsSection($root);
        $this->addPersistenceSection($root);
        $this->addMqttSection($root);
        $this->addControlSection($root);
        $this->addHttpSection($root);
        $this->addCodegenSection($root);
        $this->addDeploySection($root);
        $this->addAppsSection($root);

        return $treeBuilder;
    }

    public function buildConfigTree(): NodeInterface
    {
        return $this->getConfigTreeBuilder()->buildTree();
    }

    private function addTopLevelSettings(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->integerNode('workers')
                    ->info('Worker processes to run. 0 means min(4, cpu count).')
                    ->min(0)
                    ->defaultValue(0)
                ->end()
                ->enumNode('log_level')
                    ->info('How much the daemon says.')
                    ->values(array_map(static fn(LogLevel $level): string => $level->value, LogLevel::configurable()))
                    ->defaultValue(LogLevel::Info->value)
                ->end()
                ->enumNode('log_format')
                    ->info('How log lines are written: line for a terminal, json for a log collector. --json-logs overrides it.')
                    ->values(array_map(static fn(LogFormat $format): string => $format->value, LogFormat::cases()))
                    ->defaultValue(LogFormat::Line->value)
                ->end()
                ->append($this->createDurationNode('shutdown_grace', 'How long a worker gets to dispose its apps before it is killed.', '5s'))
                ->integerNode('worker_event_buffer')
                    ->info('Topic messages and Home Assistant events buffered per worker, together up to this many, before the oldest are dropped.')
                    ->min(1)
                    ->defaultValue(1000)
                ->end()
                ->integerNode('worker_state_batch')
                    ->info('State changes sent to a worker in one message at most.')
                    ->min(1)
                    ->defaultValue(256)
                ->end()
                ->integerNode('subscription_buffer')
                    ->info('Events queued per subscription while its handler is busy, before the oldest are dropped.')
                    ->min(1)
                    ->defaultValue(100)
                ->end()
            ->end();
    }

    /** @param Closure(string): bool $accepts */
    private function requireKeysMatching(ArrayNodeDefinition $node, Closure $accepts, string $expected): ArrayNodeDefinition
    {
        $node
            ->validate()
                ->ifTrue(static fn(array $map): bool => array_any(array_keys($map), static fn(int|string $key): bool => !$accepts((string) $key)))
                ->then(static function (array $map) use ($accepts, $expected): never {
                    $bad = array_find(array_keys($map), static fn(int|string $key): bool => !$accepts((string) $key));

                    throw new InvalidArgumentException(\sprintf('"%s" is not %s.', $bad, $expected));
                })
            ->end();

        return $node;
    }

    private function createDurationNode(string $name, string $info, string $default): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->info($info)->defaultValue($default);

        return $node;
    }

    private function appendBackoffNodes(NodeBuilder $children, string $keyPrefix, string $firstAttempt, string $maxDelay): NodeBuilder
    {
        return $children
            ->append($this->createDurationNode($keyPrefix . 'initial_delay', \sprintf('Wait before the first %s. Doubles per attempt.', $firstAttempt), '1s'))
            ->append($this->createDurationNode($keyPrefix . 'max_delay', 'Ceiling for the doubling.', $maxDelay));
    }

    private function createScalarNode(string $name, string $info, ?string $default): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node->info($info)->defaultValue($default);

        return $node;
    }

    private function addHomeAssistantSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('home_assistant')
                    ->info('The instance to connect to. `run` and `generate` need it. The token is a secret: set it from the environment.')
                    ->children()
                        ->append($this->createWebsocketUrlNode())
                        ->scalarNode('token')
                            ->info('A long-lived access token: Home Assistant, Profile -> Security.')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->append($this->createDurationNode('connect_timeout', 'How long opening the websocket and authenticating may take.', '10s'))
                        ->append($this->createDurationNode('command_timeout', 'How long Home Assistant may take to answer one command.', '30s'))
                        ->append($this->createSizeLimitNode('message_size_limit', 'A full get_states for a large instance is several megabytes in one message.'))
                        ->append($this->createSizeLimitNode('frame_size_limit', 'The frame limit bites before the message limit, and looks like a disconnect.'))
                        ->append($this->createDurationNode('heartbeat_interval', 'Time between websocket pings that detect a silently dead connection. Rounded to whole seconds.', '10s'))
                        ->integerNode('heartbeat_missed_limit')
                            ->info('Unanswered pings before the connection is treated as lost.')
                            ->min(1)
                            ->defaultValue(3)
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addReconnectSection(ArrayNodeDefinition $root): void
    {
        $reconnect = $root
            ->children()
                ->arrayNode('reconnect')
                    ->info('How long the broker waits between attempts to reach Home Assistant again. It never stops trying.')
                    ->addDefaultsIfNotSet();

        $this->appendBackoffNodes($reconnect->children(), '', 'retry', '60s');
    }

    private function addSupervisionSection(ArrayNodeDefinition $root): void
    {
        $supervision = $root
            ->children()
                ->arrayNode('supervision')
                    ->info('What the broker does about workers that die or stop responding.')
                    ->addDefaultsIfNotSet();

        $children = $supervision->children()
            ->integerNode('restart_attempts')
                ->info('Restarts allowed inside the window before a worker is quarantined and its apps stay down.')
                ->min(0)
                ->defaultValue(5)
            ->end()
            ->append($this->createDurationNode('restart_window', 'Span the restart count is measured over.', '60s'));

        $this->appendBackoffNodes($children, 'restart_', 'restart of a worker', '30s')
            ->append($this->createDurationNode('ping_interval', 'Time between liveness probes. "off" disables the watchdog.', '10s'))
            ->append($this->createDurationNode('lag_threshold', 'Round-trip lag before a worker is reported as falling behind.', '500ms'))
            ->integerNode('unresponsive_after')
                ->info('Missed liveness probes before a worker is killed and restarted. 0 only logs.')
                ->min(0)
                ->defaultValue(3)
            ->end()
            ->append($this->createDurationNode('initialize_timeout', 'Time an app gets in initialize() before it is dropped; apps initialize concurrently. "off" waits forever.', '60s'))
            ->append($this->createDurationNode('ready_timeout', 'Time a worker gets to report its apps ready before it is restarted; keep it above initialize_timeout. "off" disables.', '5m'));
    }

    private function addPersistenceSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('persistence')
                    ->info('Where apps keep what must survive a restart. Without a url, an app that asks for a store is not started.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->createScalarNode('url', 'Storage URL, for example redis://valkey:6379/0. Credentials and the database index belong in it.', null))
                        ->append($this->createScalarNode('prefix', 'What every key is filed under, so two installations can share one server.', 'stewart'))
                        ->append($this->createDurationNode('timeout', 'How long one storage operation may take.', '2s'))
                        ->append($this->createDurationNode('recovery_interval', 'After the store times out or is unreachable, how long a worker fails store calls at once before it tries again.', '5s'))
                    ->end()
                ->end()
            ->end();
    }

    private function addMqttSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('mqtt')
                    ->info('The MQTT server apps exchange messages with. Without a url, an app that asks for Mqtt is not started. Needs stewart-php/mqtt.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->createScalarNode('url', 'Server URL, mqtt://host:1883 or mqtts://host:8883 for TLS. Credentials belong in it: mqtt://user:password@host.', null))
                        ->append($this->createScalarNode('client_id', 'Client identifier the server knows the daemon by. Defaults to stewart-<hostname>.', null))
                        ->append($this->createDurationNode('keepalive', 'Longest silence before the connection is probed, and dropped when the probe goes unanswered.', '30s'))
                        ->append($this->createDurationNode('connect_timeout', 'How long connecting and the CONNECT handshake may take.', '10s'))
                        ->append($this->createDurationNode('reconnect_initial_delay', 'Wait before the first reconnect to the MQTT server. Doubles per attempt.', '1s'))
                        ->append($this->createDurationNode('reconnect_max_delay', 'Ceiling for the doubling.', '60s'))
                        ->integerNode('outbound_buffer')
                            ->info('QoS 1 messages kept while the server is unreachable, before the oldest are dropped. QoS 0 messages are dropped at once.')
                            ->min(0)
                            ->defaultValue(100)
                        ->end()
                        ->arrayNode('will')
                            ->info('Message the server publishes when the daemon disappears without disconnecting.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append($this->createScalarNode('topic', 'Topic of the last will. Unset sends none.', null))
                                ->append($this->createScalarNode('payload', 'Payload of the last will.', ''))
                                ->integerNode('qos')
                                    ->info('Delivery guarantee: 0 at most once, 1 at least once.')
                                    ->min(0)
                                    ->max(1)
                                    ->defaultValue(0)
                                ->end()
                                ->booleanNode('retain')
                                    ->info('Whether the server keeps the last will for later subscribers.')
                                    ->defaultFalse()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addControlSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('control')
                    ->info('The read-only socket `stewart status` connects to. The token is a secret: set it from the environment.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->createScalarNode('listen', 'unix://path, relative to the project root, or tcp://host:port. "off" disables the socket; an empty environment variable means unset, so use the word.', 'unix://' . ControlAddress::DEFAULT_SOCKET))
                        ->scalarNode('token')
                            ->info('Shared secret every client presents. Required while listen is set: the daemon refuses to start without one.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addHttpSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('http')
                    ->info('The read-only HTTP port that answers /healthz and /readyz, for Kubernetes probes.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->createScalarNode('listen', 'tcp://ip:port, such as tcp://0.0.0.0:8080 or tcp://[::]:8080. "off" disables the port.', self::OFF))
                        ->arrayNode('admin')
                            ->info('The HTTP admin API that lists, pauses and resumes apps. The token is a secret: set it from the environment.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append($this->createScalarNode('listen', 'tcp://ip:port, such as tcp://127.0.0.1:8081. "off" disables the API.', self::OFF))
                                ->scalarNode('token')
                                    ->info('Bearer token every request presents. Required while listen is set: the daemon refuses to start without one.')
                                    ->defaultNull()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addCodegenSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('codegen')
                    ->info('What `stewart generate` writes: typed classes over the entities and services of your own instance.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->createScalarNode('namespace', 'Namespace of the generated classes. Composer must map it to output_dir.', 'App\\Generated'))
                        ->append($this->createScalarNode('output_dir', 'Directory the classes are written to, relative to the project root.', 'generated'))
                        ->append($this->createPatternListNode('include', 'Entity selectors that get classes. Exact IDs or globs (* and ?).', ['*']))
                        ->append($this->createPatternListNode('exclude', 'Entity selectors that do not, whatever include says.', []))
                        ->append($this->createDomainAttributesNode())
                    ->end()
                ->end()
            ->end();
    }

    private function addDeploySection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('deploy')
                    ->info('Deploying new commits while running; needs the runtime image cloning the project (STEWART_BOOT_GIT_URL).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('git')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append($this->createDurationNode('poll', 'How often to fetch the tracked ref and deploy a new commit that passes `stewart check`. "off" disables it.', self::OFF))
                                ->append($this->createDurationNode('prepare_timeout', 'Longest a fetch, dependency install and check of one commit may take.', '10m'))
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function createWebsocketUrlNode(): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition('url');
        $node
            ->info('http:// and https:// are accepted and rewritten; /api/websocket is appended unless the path already ends in /websocket.')
            ->isRequired()
            ->cannotBeEmpty()
            ->beforeNormalization()
                ->ifString()
                ->then(static fn(string $url): string => HomeAssistantUrl::tryParse($url)?->reveal() ?? $url)
            ->end();

        return $node;
    }

    private function createSizeLimitNode(string $name, string $info): IntegerNodeDefinition
    {
        $node = new IntegerNodeDefinition($name);
        $node->info($info)->min(1)->defaultValue(self::DEFAULT_SIZE_LIMIT_BYTES);

        return $node;
    }

    /** @param list<string> $default */
    private function createPatternListNode(string $name, string $info, array $default): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->info($info)
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->performNoDeepMerging()
            ->defaultValue($default);

        return $node;
    }

    private function createDomainAttributesNode(): ArrayNodeDefinition
    {
        $node = $this->requireKeysMatching(new ArrayNodeDefinition('attributes'), static fn(string $domain): bool => preg_match(self::DOMAIN_KEY, $domain) === 1, 'a domain such as "sensor"');
        $node
            ->info('Per-domain attribute keys for the state classes, keyed by domain. Known Home Assistant attributes get typed accessors; everything else stays reachable through attributes().')
            ->normalizeKeys(false)
            ->arrayPrototype()
                ->children()
                    ->append($this->createPatternListNode('include', 'Keys or globs that get an accessor when seen, in addition to the known ones.', []))
                    ->append($this->createPatternListNode('exclude', 'Keys or globs that never get one, known or included.', []))
                ->end()
            ->end();

        return $node;
    }

    private function addServiceCallsSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('service_calls')
                    ->info('Bounds on the service calls workers may have in flight through the shared connection.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_in_flight_per_worker')
                            ->info('Calls one worker may have outstanding before further ones are refused. 0 is unlimited.')
                            ->min(0)
                            ->defaultValue(64)
                        ->end()
                        ->integerNode('max_in_flight')
                            ->info('Calls the whole broker may have outstanding. 0 is unlimited.')
                            ->min(0)
                            ->defaultValue(256)
                        ->end()
                        ->booleanNode('dry_run')
                            ->info('Log service calls and answer them as succeeded instead of sending them, to develop against a live Home Assistant.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addAppsSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->append($this->requireKeysMatching(new ArrayNodeDefinition('apps'), static fn(string $id): bool => AppId::tryFromString($id) !== null, 'an automation ID. IDs start with a letter and hold only lowercase letters, digits, "-" and "_"')
                    ->info('Per-app overrides, keyed by the ID in the automation\'s #[Automation] attribute. Automations are found by scanning, not listed here.')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        // integerNode() rejects an explicit null, so `worker: ~` is removed before validation.
                        ->beforeNormalization()
                            ->ifTrue(static fn(mixed $app): bool => \is_array($app)
                                && \array_key_exists('worker', $app)
                                && $app['worker'] === null)
                            ->then(static function (mixed $app): mixed {
                                if (\is_array($app)) {
                                    unset($app['worker']);
                                }

                                return $app;
                            })
                        ->end()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->booleanNode('paused')
                                ->info('Start loaded but paused: no events or schedule runs reach it until resumed.')
                                ->defaultFalse()
                            ->end()
                            ->integerNode('worker')
                                ->info('Pin to a worker index. Omit to have it distributed round-robin.')
                                ->min(0)
                                ->defaultNull()
                            ->end()
                            ->arrayNode('options')
                                ->info('Passed to the app constructor as named arguments.')
                                ->normalizeKeys(false)
                                ->defaultValue([])
                                ->variablePrototype()->end()
                            ->end()
                        ->end()
                    ->end())
            ->end();
    }
}
