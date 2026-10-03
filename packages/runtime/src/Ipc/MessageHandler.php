<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

/** @template T of object */
interface MessageHandler
{
    /** @return class-string<T> */
    public function handledMessageClass(): string;
}
