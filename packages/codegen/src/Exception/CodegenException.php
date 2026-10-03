<?php

declare(strict_types=1);

namespace Stewart\Codegen\Exception;

use Stewart\Client\HaCoreState;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<CodegenError> */
final class CodegenException extends StewartException
{
    public static function snapshotUnreadable(string $path): self
    {
        return self::createForReason(CodegenError::SnapshotUnreadable, ['path' => $path]);
    }

    public static function snapshotNotJson(string $path, string $reason): self
    {
        return self::createForReason(CodegenError::SnapshotNotJson, ['path' => $path, 'reason' => $reason]);
    }

    public static function notASnapshot(string $path): self
    {
        return self::createForReason(CodegenError::NotASnapshot, ['path' => $path]);
    }

    public static function outputDirectoryNotCreatable(string $path): self
    {
        return self::createForReason(CodegenError::OutputDirectoryNotCreatable, ['path' => $path]);
    }

    public static function fileNotWritable(string $path): self
    {
        return self::createForReason(CodegenError::FileNotWritable, ['path' => $path]);
    }

    public static function fileNotDeletable(string $path): self
    {
        return self::createForReason(CodegenError::FileNotDeletable, ['path' => $path]);
    }

    public static function unmarkedFileInTheWay(string $path): self
    {
        return self::createForReason(CodegenError::UnmarkedFileInTheWay, ['path' => $path]);
    }

    public static function shrinkRefused(int $deleted, int $existing): self
    {
        return self::createForReason(CodegenError::ShrinkRefused, ['deleted' => $deleted, 'existing' => $existing]);
    }

    public static function homeAssistantNotRunning(HaCoreState $state): self
    {
        return self::createForReason(CodegenError::HomeAssistantNotRunning, ['state' => $state->value]);
    }

    public static function renameCollision(EntityId $entity, string $name, EntityId $other): self
    {
        return self::createForReason(CodegenError::RenameCollision, ['entity' => $entity->value, 'name' => $name, 'other' => $other->value]);
    }

    public static function renameReserved(EntityId $entity, string $name): self
    {
        return self::createForReason(CodegenError::RenameReserved, ['entity' => $entity->value, 'name' => $name]);
    }
}
