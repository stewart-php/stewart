<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

final readonly class ExternalCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function findFirstErrorLine(): string
    {
        return $this->listErrorLines()[0] ?? '';
    }

    public function findLastErrorLine(): string
    {
        return array_last($this->listErrorLines()) ?? '';
    }

    /** @return list<string> */
    private function listErrorLines(): array
    {
        $lines = array_map(trim(...), explode("\n", $this->errorOutput));

        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }
}
