<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Control;

use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\Request\ControlRequestHandler;

/** @implements ControlRequestHandler<SnapshotRequest> */
final readonly class StubSnapshotRequestHandler implements ControlRequestHandler
{
    public function __construct(private StubSnapshotSource $snapshots) {}

    public function handledMessageClass(): string
    {
        return SnapshotRequest::class;
    }

    public function answerRequest(ClientFrame $request): ServerFrame
    {
        return new SnapshotFrame($this->snapshots->takeSnapshot());
    }
}
