<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Doctor;

use Stewart\Client\Component\ComponentProtocol;
use Stewart\Client\Component\ComponentVersion;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Client\HaClientFactory;
use Stewart\Client\HaConfig;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Config\HttpConfig;
use Stewart\Runtime\Config\PersistenceConfig;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Logging\LoggerFactory;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Store\StoreBackendConnector;
use Stewart\Store\StoreTiming;

final readonly class ConnectionChecks
{
    private const string ENVIRONMENT_FILE = '.env';

    public function __construct(
        private ConfigLoader $configs,
        private ProjectRoot $projectRoot,
        private HaClientFactory $haClients,
        private StoreBackendConnector $storeBackends,
        private LoggerFactory $loggers,
    ) {}

    /** @return list<DoctorFinding> */
    public function runConnectionChecks(?string $configPath): array
    {
        $environmentFile = is_file($this->projectRoot->resolvePath(self::ENVIRONMENT_FILE))
            ? new DoctorFinding('.env', DoctorOutcome::Pass, 'found')
            : new DoctorFinding('.env', DoctorOutcome::Warn, 'missing; `make env` creates it, unless the environment sets everything');

        try {
            $config = $this->configs->loadConfig($configPath);
        } catch (StewartException $e) {
            return [$environmentFile, new DoctorFinding('config', DoctorOutcome::Fail, $e->getMessage())];
        }

        $configFile = $this->configs->findMissingDefaultFile($configPath) === null
            ? new DoctorFinding('config', DoctorOutcome::Pass, 'loads')
            : new DoctorFinding('config', DoctorOutcome::Pass, 'loads from the environment; there is no stewart.yaml');

        return [$environmentFile, $configFile, ...$this->checkHttpListeners($config->http), ...$this->checkHomeAssistant($config->homeAssistant, $config->expose), $this->checkStore($config->persistence)];
    }

    /** @return list<DoctorFinding> */
    private function checkHttpListeners(HttpConfig $http): array
    {
        $listeners = array_filter([
            'probe' => $http->listen,
            'admin' => $http->admin->listen,
            'metrics' => $http->metrics->listen,
        ]);

        if ($listeners === []) {
            return [];
        }

        try {
            $http->requireValidListeners();
        } catch (StewartException $e) {
            return [new DoctorFinding('http', DoctorOutcome::Fail, $e->getMessage())];
        }

        $described = [];

        foreach ($listeners as $role => $address) {
            $described[] = $role === 'metrics' && $http->metrics->token === null ? \sprintf('%s %s (no token)', $role, $address) : \sprintf('%s %s', $role, $address);
        }

        return [new DoctorFinding('http', DoctorOutcome::Pass, implode(', ', $described))];
    }

    /** @return list<DoctorFinding> */
    private function checkHomeAssistant(?ConnectionConfig $connection, ExposeConfig $expose): array
    {
        if ($connection === null) {
            return [new DoctorFinding('connection', DoctorOutcome::Skip, 'set STEWART_HOME_ASSISTANT__URL and STEWART_HOME_ASSISTANT__TOKEN in .env')];
        }

        $client = $this->haClients->createClient($connection, $this->loggers->createStdoutLogger(LogLevel::Error));

        try {
            $client->connect();

            return $this->checkConnectedHomeAssistant($client, $connection, $expose);
        } catch (HaClientException $e) {
            return [new DoctorFinding('connection', DoctorOutcome::Fail, $e->getMessage())];
        } finally {
            $client->close();
        }
    }

    /**
     * @return list<DoctorFinding>
     * @throws HaClientException
     */
    private function checkConnectedHomeAssistant(HaClient $client, ConnectionConfig $connection, ExposeConfig $expose): array
    {
        $user = $client->fetchCurrentUser();
        $userName = $user->name ?? $user->id;

        return [
            new DoctorFinding('connection', DoctorOutcome::Pass, \sprintf('Home Assistant %s at %s', $client->getHaVersion() ?? 'of unknown version', $connection->url)),
            $user->isAdmin
                ? new DoctorFinding('token', DoctorOutcome::Pass, \sprintf('belongs to %s, an administrator', $userName))
                : new DoctorFinding('token', DoctorOutcome::Fail, \sprintf('belongs to %s, who is not an administrator; Stewart needs one to receive every event', $userName)),
            $this->checkLocation($client->getConfig()),
            $this->checkIntegrationIfEnabled($client, $user->isAdmin, $expose),
        ];
    }

    /** @throws HaClientException */
    private function checkIntegrationIfEnabled(HaClient $client, bool $asAdministrator, ExposeConfig $expose): DoctorFinding
    {
        if (!$expose->enabled) {
            return new DoctorFinding('integration', DoctorOutcome::Skip, 'expose.enabled is off');
        }

        return $asAdministrator
            ? $this->checkIntegration($client->findComponentVersion())
            : new DoctorFinding('integration', DoctorOutcome::Skip, 'only an administrator token can ask for it');
    }

    private function checkLocation(HaConfig $haConfig): DoctorFinding
    {
        $location = $haConfig->location;

        return $location === null
            ? new DoctorFinding('location', DoctorOutcome::Warn, 'Home Assistant has none, so sun schedules fail; set it under Settings → System → General')
            : new DoctorFinding('location', DoctorOutcome::Pass, \sprintf('%.4f, %.4f', $location->latitude, $location->longitude));
    }

    private function checkIntegration(?ComponentVersion $version): DoctorFinding
    {
        if ($version === null) {
            return new DoctorFinding('integration', DoctorOutcome::Warn, 'not installed; apps need it only to expose entities');
        }

        return $version->isCompatible()
            ? new DoctorFinding('integration', DoctorOutcome::Pass, 'version ' . $version->componentVersion)
            : new DoctorFinding('integration', DoctorOutcome::Fail, \sprintf(
                'version %s speaks protocol %d, Stewart speaks %d; install the integration of the same version as Stewart',
                $version->componentVersion,
                $version->protocol,
                ComponentProtocol::VERSION,
            ));
    }

    private function checkStore(PersistenceConfig $persistence): DoctorFinding
    {
        $url = $persistence->url;

        if ($url === null) {
            return new DoctorFinding('store', DoctorOutcome::Skip, 'persistence.url is not set, so apps that take a Store do not start');
        }

        try {
            $this->storeBackends->connectToBackend($url, new StoreTiming($persistence->timeout, $persistence->recoveryInterval))->probe();
        } catch (StoreException|StoreSetupException $e) {
            return new DoctorFinding('store', DoctorOutcome::Fail, $e->getMessage());
        }

        return new DoctorFinding('store', DoctorOutcome::Pass, 'answers at ' . $url);
    }
}
