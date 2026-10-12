<?php

declare(strict_types=1);

namespace Stewart\Codegen\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<CodegenError> */
final class CodegenException extends StewartException
{
    public static function fileNotWritable(string $path): self
    {
        return self::createForReason(CodegenError::FileNotWritable, path: $path);
    }
}
