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
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Store\Keyspace;
use Stewart\Store\StoreBackend;
use Stewart\Store\StorePrefix;

final readonly class AppPauseOverrideStore
{
    private const string KEY_STEM = 'app-pause:';

    private ?Keyspace $keyspace;

    public function __construct(
        private AppPauseOverrideCodec $codec,
        private LoggerInterface $logger,
        private ?StoreBackend $store = null,
        ?StorePrefix $appPauseOverridePrefix = null,
    ) {
        $this->keyspace = $appPauseOverridePrefix === null ? null : Keyspace::forRuntime($appPauseOverridePrefix);
    }

    /** @throws StoreException */
    public function loadOverrides(): AppPauseOverrideCollection
    {
        $store = $this->store;
        $keyspace = $this->keyspace;

        if ($store === null || $keyspace === null) {
            return AppPauseOverrideCollection::keyedByAppId([]);
        }

        $overrides = [];

        foreach ($store->keysWithPrefix($keyspace->prefix . self::KEY_STEM) as $fullKey) {
            $override = $this->readOverride($store, $keyspace, $fullKey);

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
        $keyspace = $this->keyspace;

        return $store !== null && $keyspace !== null && $store->exists($this->buildOverrideKey($keyspace, $appId));
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
        $keyspace = $this->keyspace;

        if ($store === null || $keyspace === null) {
            return AppPauseOverridePersistence::NotConfigured;
        }

        try {
            $write($store, $this->buildOverrideKey($keyspace, $appId));
        } catch (JsonException|StewartException $e) {
            $this->logger->warning('Could not store the pause override', ['app' => $appId->value, 'exception' => $e]);

            return AppPauseOverridePersistence::Failed;
        }

        return AppPauseOverridePersistence::Stored;
    }

    /** @throws StoreException */
    private function buildOverrideKey(Keyspace $keyspace, AppId $appId): string
    {
        return $keyspace->buildFullKey(self::KEY_STEM . $appId->value);
    }

    /** @throws StoreException */
    private function readOverride(StoreBackend $store, Keyspace $keyspace, string $fullKey): ?AppPauseOverride
    {
        $json = $store->read($fullKey);

        if ($json === null) {
            return null;
        }

        try {
            return $this->codec->decodeOverride(new AppId(substr($keyspace->extractRelativeKey($fullKey), \strlen(self::KEY_STEM))), $json);
        } catch (JsonException|StewartException $e) {
            $this->logger->warning('Ignoring an unreadable pause override', ['key' => $fullKey, 'exception' => $e]);

            return null;
        }
    }
}
