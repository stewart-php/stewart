<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\HandlerWrapper;
use Monolog\LogRecord;
use Stewart\Runtime\Model\LogLevel;

// Monolog asks isHandling() only before the first handler runs, so handle() must decide again.
final class VerbosityFilterHandler extends HandlerWrapper
{
    public function __construct(
        HandlerInterface $handler,
        private readonly LogVerbosityPolicy $verbosity,
    ) {
        parent::__construct($handler);
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->admitsRecord($record) && parent::isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        return $this->admitsRecord($record) && parent::handle($this->labelWithSource($record));
    }

    /** @param array<LogRecord> $records */
    public function handleBatch(array $records): void
    {
        parent::handleBatch(array_map($this->labelWithSource(...), array_values(array_filter($records, $this->admitsRecord(...)))));
    }

    private function admitsRecord(LogRecord $record): bool
    {
        return $this->verbosity->admitsLevel(LogLevel::from($record->level->toPsrLogLevel()), LogOrigin::findInContext($record->context)?->scope);
    }

    private function labelWithSource(LogRecord $record): LogRecord
    {
        $origin = LogOrigin::findInContext($record->context) ?? LogOrigin::broker();
        $context = $record->context;
        unset($context[LogOrigin::CONTEXT_KEY]);

        return $record->with(context: $context, extra: [...$record->extra, LogOrigin::SOURCE_KEY => $origin->label]);
    }
}
