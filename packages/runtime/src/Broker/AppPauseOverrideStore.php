<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\App\Collection\AppPauseOverrideCollection;
use Stewart\Runtime\Config\PersistenceConfig;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Store\Keyspace;
use Stewart\Store\StoreBackend;

final readonly class AppPauseOverrideStore
{
    private const string KEY_STEM = 'app-pause:';

    private Keyspace $keyspace;

    public function __construct(
        private AppPauseOverrideCodec $codec,
        private LoggerInterface $logger,
        PersistenceConfig $persistenceConfig,
        private ?StoreBackend $store = null,
    ) {
        $this->keyspace = Keyspace::forRuntime($persistenceConfig->prefix);
    }

    /** @throws StoreException */
    public function loadOverrides(): AppPauseOverrideCollection
    {
        $store = $this->store;

        if ($store === null) {
            return AppPauseOverrideCollection::keyedByAppId([]);
        }

        $overrides = [];

        foreach ($store->keysWithPrefix($this->keyspace->prefix . self::KEY_STEM) as $fullKey) {
            $override = $this->readOverride($store, $fullKey);

            if ($override !== null) {
                $overrides[] = $override;
            }
        }

        return AppPauseOverrideCollection::keyedByAppId($overrides);
    }

    /** @throws StoreException */
    public function hasOverride(AppId $appId): bool
    {
        $store = $this->store;

        return $store !== null && $store->exists($this->buildOverrideKey($appId));
    }

    public function saveOverride(AppPauseOverride $override): AppPauseOverridePersistence
    {
        return $this->runWrite($override->appId, fn(StoreBackend $store, string $fullKey) => $store->write($fullKey, $this->codec->encodeOverride($override), null));
    }

    public function removeOverride(AppId $appId): AppPauseOverridePersistence
    {
        return $this->runWrite($appId, static fn(StoreBackend $store, string $fullKey) => $store->remove($fullKey));
    }

    /** @param Closure(StoreBackend, string): void $write */
    private function runWrite(AppId $appId, Closure $write): AppPauseOverridePersistence
    {
        $store = $this->store;

        if ($store === null) {
            return AppPauseOverridePersistence::NotConfigured;
        }

        try {
            $write($store, $this->buildOverrideKey($appId));
        } catch (JsonException|StewartException $e) {
            $this->logger->warning('Could not store the pause override', ['app' => $appId->value, 'exception' => $e]);

            return AppPauseOverridePersistence::Failed;
        }

        return AppPauseOverridePersistence::Stored;
    }

    /** @throws StoreException */
    private function buildOverrideKey(AppId $appId): string
    {
        return $this->keyspace->buildFullKey(self::KEY_STEM . $appId->value);
    }

    /** @throws StoreException */
    private function readOverride(StoreBackend $store, string $fullKey): ?AppPauseOverride
    {
        $json = $store->read($fullKey);

        if ($json === null) {
            return null;
        }

        try {
            return $this->codec->decodeOverride(new AppId(substr($this->keyspace->extractRelativeKey($fullKey), \strlen(self::KEY_STEM))), $json);
        } catch (JsonException|StewartException $e) {
            $this->logger->warning('Ignoring an unreadable pause override', ['key' => $fullKey, 'exception' => $e]);

            return null;
        }
    }
}
