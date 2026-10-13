<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Closure;
use Stewart\Runtime\Config\Environment\EnvironmentValueParser;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Support\Text\ClosestNameFinder;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;

final readonly class EnvironmentOverlay
{
    public const string PREFIX = 'STEWART_';

    public const string ENTRYPOINT_PREFIX = self::PREFIX . 'BOOT_';

    private const string SEPARATOR = '__';

    private const string SECRET_FILE_SUFFIX = '_file';

    // Kubernetes names these after every Service in the namespace, ours and others like stewart-valkey alike.
    private const string KUBERNETES_SERVICE_LINK = '/\A' . self::PREFIX . '(?:[A-Z0-9]+_)*(?:SERVICE_HOST|SERVICE_PORT(?:_[A-Z0-9_]+)?|PORT(?:_\d+_(?:TCP|UDP|SCTP)(?:_(?:PROTO|PORT|ADDR))?)?)\z/';

    public function __construct(
        private EnvironmentVariables $variables,
        private EnvironmentValueParser $environmentValueParser,
        private ClosestNameFinder $closestNameFinder,
        private SecretFileReader $secretFiles,
    ) {}

    /**
     * @param array<array-key, mixed> $file
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    public function collectEnvironmentOverrides(NodeInterface $tree, array $file): array
    {
        $overrides = [];
        $variableByPath = [];

        foreach ($this->variables->filterByPrefix(self::PREFIX) as $name => $raw) {
            if ($this->isReadElsewhere($name)) {
                continue;
            }

            $segments = array_values(array_filter(
                explode(self::SEPARATOR, substr($name, \strlen(self::PREFIX))),
                static fn(string $segment): bool => $segment !== '',
            ));

            if ($segments === []) {
                continue;
            }

            $target = $this->resolveOverrideTarget($tree, $file, $segments, $name);

            if (!$this->environmentValueParser->supportsNode($target->node)) {
                throw ConfigurationException::createForReason(ConfigurationError::EnvironmentVariableNamesSection, variable: $name, separator: self::SEPARATOR);
            }

            $valuePath = implode('.', $target->path);

            if (isset($variableByPath[$valuePath])) {
                throw ConfigurationException::createForReason(
                    ConfigurationError::EnvironmentVariableConflict,
                    variable: $variableByPath[$valuePath],
                    otherVariable: $name,
                    valuePath: $valuePath,
                );
            }

            $variableByPath[$valuePath] = $name;
            $value = $target->readsSecretFile ? $this->secretFiles->readSecretFile($name, $raw) : $raw;
            $overrides = $this->withValueAtPath($overrides, $target->path, $this->environmentValueParser->parseForNode($target->node, $name, $value));
        }

        return $overrides;
    }

    private function isReadElsewhere(string $name): bool
    {
        return str_starts_with($name, self::ENTRYPOINT_PREFIX) || preg_match(self::KUBERNETES_SERVICE_LINK, $name) === 1;
    }

    /**
     * @param array<array-key, mixed> $file
     * @param non-empty-list<string> $segments
     * @throws ConfigurationException
     */
    private function resolveOverrideTarget(NodeInterface $node, array $file, array $segments, string $name): EnvironmentOverrideTarget
    {
        $path = [];
        $lastSegment = array_key_last($segments);

        foreach ($segments as $index => $segment) {
            if (!$node instanceof ArrayNode) {
                throw ConfigurationException::createForReason(ConfigurationError::EnvironmentVariableTooDeep, variable: $name, valuePath: implode('.', $path));
            }

            if ($node instanceof PrototypedArrayNode) {
                $key = $this->findMatchingFileKey($segment, $file);
                $node = $node->getPrototype();
            } else {
                $secretKey = $index === $lastSegment ? $this->findSecretFileKey($node, $segment) : null;

                if ($secretKey !== null) {
                    return new EnvironmentOverrideTarget([...$path, $secretKey], $node->getChildren()[$secretKey], readsSecretFile: true);
                }

                $key = $this->resolveChildKey($node, $segment, $path, $name);
                $node = $node->getChildren()[$key];
            }

            $nested = $file[$key] ?? [];
            $file = \is_array($nested) ? $nested : [];
            $path[] = $key;
        }

        return new EnvironmentOverrideTarget($path, $node);
    }

    /**
     * @param array<array-key, mixed> $values
     * @param non-empty-list<string> $path
     * @return array<array-key, mixed>
     */
    private function withValueAtPath(array $values, array $path, mixed $value): array
    {
        $key = array_shift($path);

        if ($path === []) {
            $values[$key] = $value;

            return $values;
        }

        $nested = $values[$key] ?? null;
        $values[$key] = $this->withValueAtPath(\is_array($nested) ? $nested : [], $path, $value);

        return $values;
    }

    /**
     * @param list<string> $path
     * @throws ConfigurationException
     */
    private function resolveChildKey(ArrayNode $node, string $segment, array $path, string $name): string
    {
        $key = strtolower($segment);
        $children = array_keys($node->getChildren());

        if (\in_array($key, $children, true)) {
            return $key;
        }

        $closestChildKey = $this->closestNameFinder->findClosestName($key, $children);

        throw ConfigurationException::environmentVariableUnknown(
            $name,
            $closestChildKey === null ? null : self::PREFIX . strtoupper(implode(self::SEPARATOR, [...$path, $closestChildKey])),
        );
    }

    private function findSecretFileKey(ArrayNode $node, string $segment): ?string
    {
        $key = strtolower($segment);
        $children = $node->getChildren();

        if (isset($children[$key]) || !str_ends_with($key, self::SECRET_FILE_SUFFIX)) {
            return null;
        }

        $secretKey = substr($key, 0, -\strlen(self::SECRET_FILE_SUFFIX));

        return isset($children[$secretKey]) && $this->environmentValueParser->supportsNode($children[$secretKey]) ? $secretKey : null;
    }

    /** @param array<array-key, mixed> $file */
    private function findMatchingFileKey(string $segment, array $file): string
    {
        $fileKeys = array_map(strval(...), array_keys($file));

        return $this->findFileKeyBy($fileKeys, $segment, $this->normalizeForKeyComparison(...))
            ?? $this->findFileKeyBy($fileKeys, $segment, $this->foldForKeyComparison(...))
            ?? strtolower($segment);
    }

    /**
     * @param list<string> $fileKeys
     * @param Closure(string): string $normalize
     */
    private function findFileKeyBy(array $fileKeys, string $segment, Closure $normalize): ?string
    {
        return array_find($fileKeys, static fn(string $existing): bool => $normalize($existing) === $normalize($segment));
    }

    private function normalizeForKeyComparison(string $key): string
    {
        // Env segments are upper case and cannot express "-", so both sides map it to "_".
        return strtolower(strtr($key, '-', '_'));
    }

    // MOTION_SENSOR and MOTIONSENSOR both stand for a camelCase key such as motionSensor.
    private function foldForKeyComparison(string $key): string
    {
        return strtolower(str_replace(['_', '-'], '', $key));
    }
}
