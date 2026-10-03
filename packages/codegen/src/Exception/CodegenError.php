<?php

declare(strict_types=1);

namespace Stewart\Codegen\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum CodegenError: string implements ExceptionReason
{
    case SnapshotUnreadable = 'snapshot_unreadable';
    case SnapshotNotJson = 'snapshot_not_json';
    case NotASnapshot = 'not_a_snapshot';
    case OutputDirectoryNotCreatable = 'output_directory_not_creatable';
    case FileNotWritable = 'file_not_writable';
    case FileNotDeletable = 'file_not_deletable';
    case UnmarkedFileInTheWay = 'unmarked_file_in_the_way';
    case ShrinkRefused = 'shrink_refused';
    case HomeAssistantNotRunning = 'home_assistant_not_running';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::SnapshotUnreadable => 'Snapshot {path} cannot be read.',
            self::SnapshotNotJson => 'Snapshot {path} is not valid JSON: {reason}',
            self::NotASnapshot => '{path} is not a snapshot; write one with `stewart generate --snapshot-out`.',
            self::OutputDirectoryNotCreatable => 'Cannot create output directory {path}.',
            self::FileNotWritable => 'Cannot write {path}.',
            self::FileNotDeletable => 'Cannot delete {path}.',
            self::UnmarkedFileInTheWay => '{path} was not written by stewart generate; move it out of the output directory.',
            self::ShrinkRefused => 'Generating would delete {deleted} of {existing} generated files; run `make generate ARGS=--allow-shrink` if that is intended.',
            self::HomeAssistantNotRunning => 'Home Assistant is {state}; run `stewart generate` again once it has started.',
        };
    }
}
