<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Control;

use RuntimeException;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\Request\ControlRequestHandler;

/** @implements ControlRequestHandler<SnapshotRequest> */
final readonly class FailingSnapshotRequestHandler implements ControlRequestHandler
{
    public function handledMessageClass(): string
    {
        return SnapshotRequest::class;
    }

    public function answerRequest(ClientFrame $request): ServerFrame
    {
        throw new RuntimeException('assembler broke');
    }
}
