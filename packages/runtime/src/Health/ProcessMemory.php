<?php

declare(strict_types=1);

namespace Stewart\Runtime\Health;

final readonly class ProcessMemory
{
    private const string STATM = '/proc/self/statm';

    public function __construct(private string $statmFile = self::STATM) {}

    public function measureResidentBytes(): int
    {
        $residentPages = $this->readResidentPages();

        return $residentPages === null ? memory_get_usage(true) : $residentPages * posix_sysconf(\POSIX_SC_PAGESIZE);
    }

    private function readResidentPages(): ?int
    {
        $statm = is_readable($this->statmFile) ? file_get_contents($this->statmFile) : false;
        // The second field is the resident set size, counted in pages.
        $residentPages = $statm === false ? null : explode(' ', trim($statm))[1] ?? null;

        return $residentPages !== null && ctype_digit($residentPages) ? (int) $residentPages : null;
    }
}
