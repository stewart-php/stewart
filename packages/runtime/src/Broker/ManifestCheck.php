<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Generated\GeneratedFormat;
use Stewart\Contracts\Generated\Manifest;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Config\CodegenConfig;

final readonly class ManifestCheck
{
    /** @param class-string<Manifest> $manifest */
    public function __construct(private string $manifest) {}

    public static function forGeneratedCode(CodegenConfig $codegen): ?self
    {
        $manifest = GeneratedRoots::fromNamespace($codegen->namespace->value)?->manifestClass;

        return $manifest === null ? null : new self($manifest);
    }

    public function readFormatVersion(): int
    {
        $constant = $this->manifest . '::' . GeneratedFormat::MANIFEST_CONSTANT;
        $version = \defined($constant) ? \constant($constant) : 0;

        return \is_int($version) ? $version : 0;
    }

    public function hasCurrentFormat(): bool
    {
        return $this->readFormatVersion() === GeneratedFormat::VERSION;
    }

    /** @param list<string> $liveEntityIds */
    public function findDrift(array $liveEntityIds): ?ManifestDrift
    {
        $manifest = $this->manifest;
        $drift = ManifestDrift::calculateBetween($manifest::listEntityIds(), $liveEntityIds, $manifest::listIgnoredEntityIds());

        return $drift->isEmpty() ? null : $drift;
    }
}
