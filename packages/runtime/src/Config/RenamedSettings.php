<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class RenamedSettings
{
    private const array NEW_NAMES_BY_OLD_NAME = ['service_calls' => 'ha_calls'];

    public function findNewName(string $topLevelKey): ?string
    {
        return self::NEW_NAMES_BY_OLD_NAME[$topLevelKey] ?? null;
    }

    /**
     * @param array<array-key, mixed> $fileConfig
     * @throws ConfigurationException
     */
    public function refuseRenamedKeys(array $fileConfig): void
    {
        foreach (self::NEW_NAMES_BY_OLD_NAME as $oldName => $newName) {
            if (\array_key_exists($oldName, $fileConfig)) {
                throw ConfigurationException::createForReason(ConfigurationError::KeyRenamed, setting: $oldName, renamedTo: $newName);
            }
        }
    }
}
