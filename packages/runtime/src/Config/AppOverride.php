<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Model\LogLevel;

final readonly class AppOverride
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public AppId $id,
        public bool $enabled,
        public bool $paused,
        public ?int $worker,
        public array $options,
        public ?LogLevel $logLevel = null,
    ) {}

    /**
     * @throws ConfigurationException
     * @throws IdentifierException
     */
    public static function fromSection(ConfigSection $app, string $id): self
    {
        $logLevel = $app->findString('log_level');

        return new self(
            id: new AppId($id),
            enabled: $app->readBool('enabled'),
            paused: $app->readBool('paused'),
            worker: $app->findInt('worker'),
            options: $app->readMap('options'),
            logLevel: $logLevel === null ? null : LogLevel::from($logLevel),
        );
    }
}
