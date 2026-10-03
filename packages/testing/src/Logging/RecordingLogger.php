<?php

declare(strict_types=1);

namespace Stewart\Testing\Logging;

use Amp\DeferredFuture;
use Amp\Future;
use Psr\Log\AbstractLogger;
use Stewart\Testing\Logging\Collection\RecordedLogCollection;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    public private(set) RecordedLogCollection $records;

    /** @var array<string, DeferredFuture<null>> */
    private array $awaited = [];

    public function __construct()
    {
        $this->records = RecordedLogCollection::empty();
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records = $this->records->withRecordedLog(new RecordedLog(\is_string($level) ? $level : 'unknown', (string) $message, $context));

        $awaited = $this->awaited[(string) $message] ?? null;
        unset($this->awaited[(string) $message]);
        $awaited?->complete();
    }

    /** @return Future<null> */
    public function waitForMessage(string $message): Future
    {
        if ($this->records->containsWhere(static fn(RecordedLog $record): bool => $record->message === $message)) {
            return Future::complete();
        }

        return ($this->awaited[$message] ??= new DeferredFuture())->getFuture();
    }

    /** @return list<string> */
    public function listMessagesAt(string $level): array
    {
        return $this->records
            ->filter(static fn(RecordedLog $record): bool => $record->level === $level)
            ->mapToList(static fn(RecordedLog $record): string => $record->message);
    }
}
