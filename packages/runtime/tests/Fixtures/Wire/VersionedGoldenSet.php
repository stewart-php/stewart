<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use PHPUnit\Framework\Assert;
use stdClass;
use Symfony\Component\Finder\Finder;

final readonly class VersionedGoldenSet
{
    private const string UPDATE_VARIABLE = 'UPDATE_GOLDEN';

    private const int PRETTY_JSON = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /** @param non-empty-list<string> $versionPath */
    public function __construct(
        private string $directory,
        private string $lockFile,
        private array $versionPath,
        private int $currentVersion,
        private string $versionConstant,
    ) {}

    /** @param array<string, string> $encodedByFile */
    public function assertEveryGoldenMatches(array $encodedByFile): void
    {
        $lockedVersion = $this->readLockedVersion();
        $missing = array_values(array_filter(array_keys($encodedByFile), fn(string $file): bool => !is_file($this->directory . '/' . $file)));
        $changed = array_values(array_filter(
            array_keys($encodedByFile),
            fn(string $file): bool => is_file($this->directory . '/' . $file) && !$this->holdsSameJson($file, $encodedByFile[$file]),
        ));
        $orphans = array_values(array_diff($this->listGoldenFiles(), array_keys($encodedByFile)));

        if (getenv(self::UPDATE_VARIABLE) === '1') {
            if ($changed !== [] && $lockedVersion === $this->currentVersion) {
                Assert::fail(\sprintf('%s changed while %s is still %d; bump it before rewriting the goldens.', implode(', ', $changed), $this->versionConstant, $this->currentVersion));
            }

            foreach ([...$missing, ...$changed] as $file) {
                file_put_contents($this->directory . '/' . $file, json_encode(json_decode($encodedByFile[$file], flags: \JSON_THROW_ON_ERROR), self::PRETTY_JSON) . "\n");
            }

            [$missing, $changed] = [[], []];
        }

        Assert::assertSame([], $orphans, 'These goldens belong to no message; delete them.');
        Assert::assertSame([], $missing, 'Goldens are missing; rerun with ' . self::UPDATE_VARIABLE . '=1.');
        Assert::assertSame([], $changed, $lockedVersion === $this->currentVersion
            ? \sprintf('The wire changed: bump %s, then rerun with %s=1.', $this->versionConstant, self::UPDATE_VARIABLE)
            : \sprintf('%s was bumped; rerun with %s=1.', $this->versionConstant, self::UPDATE_VARIABLE));
    }

    private function readLockedVersion(): ?int
    {
        $path = $this->directory . '/' . $this->lockFile;

        if (!is_file($path)) {
            return null;
        }

        $value = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($this->versionPath as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return \is_int($value) ? $value : null;
    }

    private function holdsSameJson(string $file, string $encoded): bool
    {
        return $this->canonicalizeJson((string) file_get_contents($this->directory . '/' . $file)) === $this->canonicalizeJson($encoded);
    }

    private function canonicalizeJson(string $json): string
    {
        return (string) json_encode($this->sortObjectKeys(json_decode($json, flags: \JSON_THROW_ON_ERROR)), self::PRETTY_JSON);
    }

    private function sortObjectKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map($this->sortObjectKeys(...), $properties);
        }

        return \is_array($value) ? array_map($this->sortObjectKeys(...), $value) : $value;
    }

    /** @return list<string> */
    private function listGoldenFiles(): array
    {
        $files = [];

        foreach (Finder::create()->files()->in($this->directory)->depth(0)->name('*.json') as $file) {
            $files[] = $file->getFilename();
        }

        return $files;
    }
}
