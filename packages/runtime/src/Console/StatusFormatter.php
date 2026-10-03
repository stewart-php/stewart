<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

final readonly class StatusFormatter
{
    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }

        if ($bytes >= 1_073_741_824) {
            return \sprintf('%.1f GiB', $bytes / 1_073_741_824);
        }

        if ($bytes >= 1_048_576) {
            return \sprintf('%.1f MiB', $bytes / 1_048_576);
        }

        if ($bytes >= 1024) {
            return \sprintf('%.0f KiB', $bytes / 1024);
        }

        return $bytes . ' B';
    }

    public function formatElapsed(?Duration $duration): string
    {
        if ($duration === null) {
            return '-';
        }

        $milliseconds = $duration->toMicroseconds() / 1_000;

        return $milliseconds >= 1000 ? \sprintf('%.1f s', $milliseconds / 1000) : \sprintf('%.1f ms', $milliseconds);
    }

    public function formatTimeAgo(?Instant $at, Instant $now): string
    {
        if ($at === null) {
            return 'never';
        }

        return $this->formatDuration($now->elapsedSince($at)) . ' ago';
    }

    public function formatTimeUntil(?Instant $at, Instant $now): string
    {
        if ($at === null) {
            return '-';
        }

        return 'in ' . $this->formatDuration($at->elapsedSince($now));
    }

    public function formatDuration(Duration $duration): string
    {
        $seconds = (int) $duration->toSeconds();

        if ($seconds < 60) {
            return \sprintf('%ds', $seconds);
        }

        if ($seconds < 3600) {
            return \sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
        }

        if ($seconds < 86400) {
            return \sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return \sprintf('%dd %02dh', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }

    public function formatYesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
