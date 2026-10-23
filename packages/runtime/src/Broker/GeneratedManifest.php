<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Generated\Manifest;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Config\CodegenConfig;
use Stewart\Runtime\Exception\AppException;
use Stewart\Support\Wire\GeneratedFormat;

// Data rather than the class, because a reload after stewart generate brings it from a process that loaded the new one.
final readonly class GeneratedManifest
{
    /**
     * @param list<string> $entityIds
     * @param list<string> $ignoredEntityIds
     */
    public function __construct(
        public int $formatVersion,
        #[ListOf('string')]
        public array $entityIds,
        #[ListOf('string')]
        public array $ignoredEntityIds,
    ) {}

    /** @param class-string<Manifest> $manifest */
    public static function readFromClass(string $manifest): self
    {
        $constant = $manifest . '::' . GeneratedFormat::MANIFEST_CONSTANT;
        $version = \defined($constant) ? \constant($constant) : 0;

        return new self(\is_int($version) ? $version : 0, $manifest::listEntityIds(), $manifest::listIgnoredEntityIds());
    }

    /** @throws AppException */
    public static function findForGeneratedCode(CodegenConfig $codegen): ?self
    {
        $manifest = GeneratedRoots::fromNamespace($codegen->namespace->value)?->manifestClass;

        return $manifest === null ? null : self::readFromClass($manifest);
    }

    public function hasCurrentFormat(): bool
    {
        return $this->formatVersion === GeneratedFormat::VERSION;
    }

    /** @param list<string> $liveEntityIds */
    public function findDrift(array $liveEntityIds): ManifestDrift
    {
        return ManifestDrift::calculateBetween($this->entityIds, $liveEntityIds, $this->ignoredEntityIds);
    }
}
