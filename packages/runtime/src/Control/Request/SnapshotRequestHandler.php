<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\SnapshotAssembler;

/** @implements ControlRequestHandler<SnapshotRequest> */
final readonly class SnapshotRequestHandler implements ControlRequestHandler
{
    public function __construct(private SnapshotAssembler $snapshots) {}

    public function handledMessageClass(): string
    {
        return SnapshotRequest::class;
    }

    public function answerRequest(ClientFrame $request): ServerFrame
    {
        return new SnapshotFrame($this->snapshots->assembleSnapshot());
    }
}
