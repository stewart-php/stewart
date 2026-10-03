<?php

declare(strict_types=1);

namespace Stewart\Contracts\Selector;

use LogicException;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Selector\Collection\SelectorCollection;

final readonly class Selector
{
    private const string ANYTHING = '*';

    private const string MQTT_LEVEL_SEPARATOR = '/';

    private const string MQTT_SINGLE_LEVEL = '+';

    private const string MQTT_MULTI_LEVEL = '#';

    private const int MQTT_MAX_FILTER_BYTES = 65535;

    private SelectorCollection $members;

    private function __construct(
        private SelectorKind $kind,
        private string $pattern,
        private ?string $compiledRegex = null,
        ?SelectorCollection $members = null,
    ) {
        $this->members = $members ?? SelectorCollection::empty();
    }

    /** @throws SelectorException */
    public static function exact(string $value): self
    {
        if ($value === '') {
            throw SelectorException::empty('An exact selector');
        }

        return new self(SelectorKind::Exact, $value);
    }

    /** @throws SelectorException */
    public static function glob(string $pattern): self
    {
        if ($pattern === '') {
            throw SelectorException::empty('A glob selector');
        }

        return new self(SelectorKind::Glob, $pattern, self::compileGlob($pattern));
    }

    /** @throws SelectorException */
    public static function regex(string $regex): self
    {
        $compileError = self::findRegexCompileError($regex);

        if ($compileError !== null) {
            throw SelectorException::regexInvalid($regex, $compileError);
        }

        return new self(SelectorKind::Regex, $regex);
    }

    /** @throws SelectorException */
    public static function mqttFilter(string $filter): self
    {
        if ($filter === '') {
            throw SelectorException::empty('An MQTT topic filter');
        }

        return new self(SelectorKind::MqttFilter, $filter, self::compileMqttFilter($filter));
    }

    /** @throws SelectorException */
    public static function anyOf(string|EntityId|self ...$selectors): self
    {
        if ($selectors === []) {
            throw SelectorException::empty('An any-of selector');
        }

        return new self(SelectorKind::AnyOf, '', members: SelectorCollection::fromSpecs(...$selectors));
    }

    public static function any(): self
    {
        return self::glob(self::ANYTHING);
    }

    /** @throws SelectorException */
    public static function fromSpec(string|EntityId|self|SelectorCollection $spec): self
    {
        return match (true) {
            $spec instanceof self => $spec,
            $spec instanceof EntityId => self::exact($spec->value),
            $spec instanceof SelectorCollection => self::fromCollection($spec),
            str_contains($spec, '*') || str_contains($spec, '?') => self::glob($spec),
            default => self::exact($spec),
        };
    }

    /** @throws SelectorException */
    private static function fromCollection(SelectorCollection $selectors): self
    {
        $members = $selectors->listValues();

        return \count($members) === 1 ? $members[0] : self::anyOf(...$members);
    }

    public function getKind(): SelectorKind
    {
        return $this->kind;
    }

    /** @throws LogicException */
    public function getPattern(): string
    {
        if ($this->kind === SelectorKind::AnyOf) {
            throw new LogicException('An any-of selector has members instead of a pattern.');
        }

        return $this->pattern;
    }

    public function listMembers(): SelectorCollection
    {
        return $this->members;
    }

    public function isAny(): bool
    {
        return $this->kind === SelectorKind::Glob && $this->pattern === self::ANYTHING;
    }

    public function matches(string $candidate): bool
    {
        return match ($this->kind) {
            SelectorKind::Exact => $candidate === $this->pattern,
            SelectorKind::AnyOf => $this->members->anyMatches($candidate),
            SelectorKind::Glob => $this->isAny() || preg_match($this->compiledRegex ?? '', $candidate) === 1,
            SelectorKind::MqttFilter => preg_match($this->compiledRegex ?? '', $candidate) === 1,
            SelectorKind::Regex => preg_match($this->pattern, $candidate) === 1,
        };
    }

    public function toCanonicalKey(): string
    {
        if ($this->isAny()) {
            return 'any';
        }

        if ($this->kind !== SelectorKind::AnyOf) {
            return $this->kind->value . ':' . $this->pattern;
        }

        $keys = $this->members->mapToList(static fn(self $member): string => $member->toCanonicalKey());
        sort($keys);

        return 'any-of:' . json_encode($keys, \JSON_THROW_ON_ERROR);
    }

    public function hasExactPattern(string $value): bool
    {
        if ($this->kind === SelectorKind::AnyOf) {
            return $this->members->containsWhere(static fn(self $member): bool => $member->hasExactPattern($value));
        }

        return $this->pattern === $value && $this->kind === SelectorKind::Exact;
    }

    public function findExactPattern(): ?string
    {
        return match ($this->kind) {
            SelectorKind::Exact => $this->pattern,
            SelectorKind::AnyOf => $this->members->count() === 1 ? $this->members->getFirst()?->findExactPattern() : null,
            default => null,
        };
    }

    private static function findRegexCompileError(string $regex): ?string
    {
        $warning = null;

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $compiled = preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }

        if ($compiled) {
            return null;
        }

        return $warning === null ? preg_last_error_msg() : preg_replace('/^preg_match\(\): /', '', $warning);
    }

    private static function compileGlob(string $pattern): string
    {
        $out = '';

        foreach (str_split($pattern) as $char) {
            $out .= match ($char) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($char, '#'),
            };
        }

        return '#\A' . $out . '\z#';
    }

    /** @throws SelectorException */
    private static function compileMqttFilter(string $filter): string
    {
        if (\strlen($filter) > self::MQTT_MAX_FILTER_BYTES || str_contains($filter, "\0")) {
            throw SelectorException::mqttFilterInvalid($filter);
        }

        $levels = explode(self::MQTT_LEVEL_SEPARATOR, $filter);
        $lastIndex = \count($levels) - 1;
        $compiledLevels = [];
        $multiLevelTail = false;

        foreach ($levels as $index => $level) {
            if ($level === self::MQTT_MULTI_LEVEL && $index === $lastIndex) {
                $multiLevelTail = true;

                continue;
            }

            if ($level === self::MQTT_SINGLE_LEVEL) {
                $compiledLevels[] = '[^/]*';

                continue;
            }

            if (str_contains($level, self::MQTT_SINGLE_LEVEL) || str_contains($level, self::MQTT_MULTI_LEVEL)) {
                throw SelectorException::mqttFilterInvalid($filter);
            }

            $compiledLevels[] = preg_quote($level, '#');
        }

        $body = implode(self::MQTT_LEVEL_SEPARATOR, $compiledLevels);

        if ($multiLevelTail) {
            $body = $compiledLevels === [] ? '.*' : $body . '(?:/.*)?';
        }

        // MQTT wildcards in the first level never match topics reserved with a leading '$'.
        $startsWithWildcard = $levels[0] === self::MQTT_SINGLE_LEVEL || $levels[0] === self::MQTT_MULTI_LEVEL;

        return '#\A' . ($startsWithWildcard ? '(?!\$)' : '') . $body . '\z#s';
    }
}
