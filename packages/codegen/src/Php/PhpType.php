<?php

declare(strict_types=1);

namespace Stewart\Codegen\Php;

final readonly class PhpType
{
    private function __construct(
        public string $native,
        public string $docblock,
    ) {}

    public static function fromNative(string $native, ?string $docblock = null): self
    {
        return new self($native, $docblock ?? $native);
    }

    public static function mixed(): self
    {
        return new self('mixed', 'mixed');
    }

    public function listOf(): self
    {
        return new self('array', 'list<' . $this->docblock . '>');
    }

    public function nullable(): self
    {
        if ($this->native === 'mixed') {
            return $this;
        }

        return new self(
            str_contains($this->native, '|') ? $this->native . '|null' : '?' . $this->native,
            $this->docblock === $this->native && self::isPlain($this->docblock) ? '?' . $this->docblock : $this->docblock . '|null',
        );
    }

    private static function isPlain(string $type): bool
    {
        return preg_match('/\\A[A-Za-z_][A-Za-z0-9_]*\\z/', $type) === 1;
    }

    public function needsDocblock(): bool
    {
        return $this->docblock !== $this->native;
    }
}
