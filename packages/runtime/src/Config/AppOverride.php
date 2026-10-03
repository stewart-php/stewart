<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class AppOverride
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public AppId $id,
        public bool $enabled,
        public ?int $worker,
        public array $options,
    ) {}

    /**
     * @throws ConfigurationException
     * @throws IdentifierException
     */
    public static function fromSection(ConfigSection $app, string $id): self
    {
        return new self(
            id: new AppId($id),
            enabled: $app->readBool('enabled'),
            worker: $app->findInt('worker'),
            options: $app->readMap('options'),
        );
    }
}
